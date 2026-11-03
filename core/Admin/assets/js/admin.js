// Velk — admin JS
// TipTap richtext editors + repeater + small interactions.

// Editor richtext — bundle Tiptap vendorizado (2.27.2), servido do próprio
// admin (/admin/__static/js/tiptap.js). Sem CDN, sem dependência de rede em
// runtime. Proveniência + como regerar: core/Admin/assets/js/tiptap.LICENSE.txt.
// `Node`/`mergeAttributes` alimentam o node Figure (imagem com legenda) abaixo.
// O ?v= é cache-bust manual: o AssetController cacheia 24h sem revalidação e
// um bundle velho + admin.js novo mataria o módulo inteiro. Bump a cada
// regeração do bundle (procedimento no tiptap.LICENSE.txt).
import { Editor, StarterKit, Link, Node, mergeAttributes, Table, TableRow, TableCell, TableHeader } from './tiptap.js?v=2.27.2-3';

// Admin URL base (e.g. "/site/admin", "/admin").
// Server-rendered into <body data-admin-base="..."> by the layout. Without
// this, fetch() calls would hardcode "/admin/..." and break whenever the
// project lives under a subpath like /site or /blog. Trailing slash
// is stripped server-side, so we always concat with a leading "/".
const ADMIN_BASE = (document.body && document.body.dataset.adminBase) || '';

// i18n bootstrap: the layouts (main.php, and embed.php for the picker
// iframe) include layout/_js_i18n.php, a JSON data block
// (<script type="application/json" id="velk-admin-i18n">) holding a flat
// {shortKey: translatedText} map built server-side from __() calls. A data
// block, not a script that sets a global: the admin CSP has no
// 'unsafe-inline' in script-src. `fb` is the ENGLISH fallback — if the
// block is ever missing or unreadable, the UI degrades to English and never
// leaks a Portuguese literal to an es/en-locale admin.
const I18N = (() => {
  const el = document.getElementById('velk-admin-i18n');
  try { return el ? JSON.parse(el.textContent) : {}; } catch { return {}; }
})();
const t = (k, fb) => I18N[k] ?? fb;

// ── Figure: imagem com legenda opcional no richtext ─────────────────────────
// Node customizado (o mecanismo padrão de extensão do Tiptap) que persiste
// <figure><img src alt><figcaption>legenda</figcaption></figure> — o markup
// que WordPress/Ghost/Medium usam pra imagem legendada. A imagem vive nos
// atributos; a legenda é o conteúdo editável. O parseHTML também reconhece
// <img> solto (conteúdo existente), então TODA imagem ganha a linha de
// legenda no editor; no save, figure com legenda vazia volta a ser <img>
// puro (cleanRichHtml) — legenda opcional, sem tag vazia no HTML salvo.
const Figure = Node.create({
  name: 'figure',
  group: 'block',
  content: 'inline*',
  isolating: true,

  addAttributes() {
    return {
      src: { default: null },
      alt: { default: null },
    };
  },

  parseHTML() {
    return [
      {
        tag: 'figure',
        // Figure sem figcaption (ex.: HTML colado) → legenda vazia; sem o
        // fallback o parser tentaria ler o <img> interno como conteúdo.
        contentElement: (dom) => dom.querySelector('figcaption') || document.createElement('figcaption'),
        getAttrs: (dom) => {
          const img = dom.querySelector('img');
          return img ? { src: img.getAttribute('src'), alt: img.getAttribute('alt') } : false;
        },
      },
      {
        tag: 'img[src]',
        getAttrs: (dom) => ({ src: dom.getAttribute('src'), alt: dom.getAttribute('alt') }),
      },
    ];
  },

  renderHTML({ HTMLAttributes }) {
    return ['figure', ['img', mergeAttributes(HTMLAttributes)], ['figcaption', 0]];
  },

  addKeyboardShortcuts() {
    return {
      // Enter dentro da legenda dividiria a figure (duplicando a imagem);
      // em vez disso, sai pra um parágrafo novo logo abaixo.
      Enter: () => {
        const { $from } = this.editor.state.selection;
        if ($from.parent.type.name !== this.name) return false;
        const after = $from.after();
        this.editor.chain().insertContentAt(after, { type: 'paragraph' }).setTextSelection(after + 1).run();
        return true;
      },
    };
  },
});

// ── Blocos do tema dentro do texto ─────────────────────────────────────────
// O tema declara blocos no site.json (`richtext_blocks`), e o campo richtext diz quais
// aceita. No texto, o bloco é um nó indivisível com dois atributos — o tipo e
// os dados —, gravado como uma tag vazia:
//   <div data-velk-block="bignumbers" data-velk-data="{…json…}"></div>
// No site, o helper richtext() do tema troca essa tag pelo partial do bloco.
//
// O bloco só existe no nível principal do texto: nunca dentro de lista,
// citação ou tabela. Por isso o nó tem grupo próprio e o `doc` é redefinido
// (com o cursor numa lista, inserir um bloco parte a lista em duas).
const VelkDocument = Node.create({ name: 'doc', topNode: true, content: '(block | velkBlock)+' });

// Uma linha de resumo para o cartão: os textos preenchidos do bloco, sem
// tags, cortados. Nunca vira HTML — o cartão a escreve com textContent.
const blockSummary = (data) => {
  const parts = [];
  const walk = (value) => {
    if (typeof value === 'string') {
      const text = value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
      // Só dígitos é quase sempre o id de uma imagem ou de um item, não texto.
      if (text !== '' && !/^\d+$/.test(text)) parts.push(text);
    } else if (value && typeof value === 'object') {
      Object.values(value).forEach(walk);
    }
  };
  walk(data);
  const line = parts.join(' · ');
  return line.length > 90 ? `${line.slice(0, 89)}…` : line;
};

// Os dados de um marcador: o objeto JSON do data-velk-data, ou null quando o
// valor não é um objeto JSON. Sem o atributo, um bloco sem dados.
const readBlockData = (value) => {
  try {
    const data = JSON.parse(value ?? '{}');
    return data && typeof data === 'object' && !Array.isArray(data) ? data : null;
  } catch {
    return null;
  }
};

// Os ícones do cartão do bloco: os mesmos da linha do repeater
// (FieldRenderer::renderRepeaterRow).
const BLOCK_HANDLE_ICON = '<svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor" aria-hidden="true"><circle cx="2" cy="3" r="1"/><circle cx="2" cy="7" r="1"/><circle cx="2" cy="11" r="1"/><circle cx="8" cy="3" r="1"/><circle cx="8" cy="7" r="1"/><circle cx="8" cy="11" r="1"/></svg>';
const BLOCK_REMOVE_ICON = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 3l6 6M9 3l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

// O nó é o mesmo em todo editor; o que muda por campo é a lista de blocos
// aceitos (`labels`: chave → nome) e o que fazer ao editar (`onEdit`).
// Fica registrado também nos campos sem blocos: abrir e salvar um texto nunca
// apaga um bloco que já estava gravado — ele aparece como indisponível, e dá
// para mover e apagar.
const createVelkBlock = ({ labels, onEdit }) => Node.create({
  name: 'velkBlock',
  group: 'velkBlock',
  atom: true,
  draggable: true,
  selectable: true,

  addAttributes() {
    return {
      type: {
        default: '',
        parseHTML: (el) => el.getAttribute('data-velk-block') || '',
        renderHTML: (attrs) => ({ 'data-velk-block': attrs.type }),
      },
      data: {
        default: {},
        parseHTML: (el) => readBlockData(el.getAttribute('data-velk-data')) ?? {},
        // Dados ilegíveis voltam como vieram (ver `raw`).
        renderHTML: (attrs) => ({ 'data-velk-data': attrs.raw ?? JSON.stringify(attrs.data) }),
      },
      // O data-velk-data que não é um objeto JSON — escrito à mão, com uma
      // vírgula sobrando ou aspas erradas — fica guardado como veio, para
      // salvar o texto não o trocar por `{}`. Não vai para o HTML por si:
      // o atributo `data` o devolve.
      raw: {
        default: null,
        parseHTML: (el) => {
          const value = el.getAttribute('data-velk-data');
          return value !== null && readBlockData(value) === null ? value : null;
        },
        renderHTML: () => ({}),
      },
      // O marcador é uma tag vazia; o que alguém tenha posto dentro dela — no
      // texto gravado, escrito por fora do painel — fica guardado e volta no
      // mesmo lugar (cleanRichHtml), em vez de sumir. Espaço em branco não
      // conta. Na colagem o conteúdo de dentro sai antes (stripDisallowedBlocks):
      // um HTML copiado de outra página não entra no texto por aqui.
      inner: {
        default: '',
        parseHTML: (el) => el.innerHTML.trim(),
        renderHTML: (attrs) => (attrs.inner ? { 'data-velk-inner': attrs.inner } : {}),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-velk-block]' }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes)];
  },

  addNodeView() {
    return ({ node, getPos, editor }) => {
      const known = labels.has(node.attrs.type);
      // Dados ilegíveis ou conteúdo dentro do marcador: a janela do bloco não
      // saberia mostrar nem gravar isso sem perder algo — sem Editar; o
      // cartão diz o que há, e o texto guarda o bloco como veio.
      const unreadable = node.attrs.raw !== null || node.attrs.inner !== '';
      const dom = document.createElement('div');
      dom.className = known && !unreadable ? 'richtext-block' : 'richtext-block is-unavailable';
      dom.contentEditable = 'false';

      // A alça só mostra que o cartão se arrasta — o cartão inteiro já é
      // arrastável (o nó é `draggable`). Os mesmos pontinhos do repeater.
      const handle = document.createElement('span');
      handle.className = 'richtext-block__handle';
      handle.setAttribute('aria-hidden', 'true');
      handle.innerHTML = BLOCK_HANDLE_ICON;

      const title = document.createElement('strong');
      title.className = 'richtext-block__title';
      title.textContent = known
        ? labels.get(node.attrs.type)
        : `${t('js_block_unavailable', 'Unavailable block')}: ${node.attrs.type}`;
      const summary = document.createElement('span');
      summary.className = 'richtext-block__summary';
      summary.textContent = unreadable
        ? t('js_block_unreadable', 'Its data can’t be read here — edit the HTML instead.')
        : blockSummary(node.attrs.data);
      const actions = document.createElement('span');
      actions.className = 'richtext-block__actions';
      dom.append(handle, title, summary, actions);

      if (known && !unreadable) {
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'button button--small richtext-block__edit';
        edit.textContent = t('js_block_edit', 'Edit');
        edit.addEventListener('click', (e) => {
          e.preventDefault();
          onEdit(node, getPos);
        });
        dom.addEventListener('dblclick', (e) => {
          if (!actions.contains(e.target)) onEdit(node, getPos);
        });
        actions.appendChild(edit);
      }

      // Remover vale também para o bloco indisponível: é o jeito à vista de
      // tirar do texto um bloco que o tema não tem mais. Sem confirmação,
      // como o × do repeater: no editor, Ctrl+Z devolve o bloco.
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'richtext-block__remove';
      remove.title = t('js_remove', 'Remove');
      remove.setAttribute('aria-label', remove.title);
      remove.innerHTML = BLOCK_REMOVE_ICON;
      remove.addEventListener('click', (e) => {
        e.preventDefault();
        const pos = typeof getPos === 'function' ? getPos() : null;
        const current = pos === null || pos === undefined ? null : editor.state.doc.nodeAt(pos);
        if (!current || current.type.name !== 'velkBlock') return;
        editor.chain().focus().deleteRange({ from: pos, to: pos + current.nodeSize }).run();
      });
      actions.appendChild(remove);

      // O que acontece nos botões fica nos botões. Sem isto, as teclas dadas
      // neles subiriam para o editor: Enter partiria o parágrafo do cursor
      // em vez de abrir a janela, e Espaço ou Backspace trocariam ou
      // apagariam o cartão que estivesse selecionado. (Arrastar a partir de
      // um botão move o cartão: o dragstart nasce no cartão, não no botão.)
      return { dom, stopEvent: (event) => actions.contains(event.target) };
    };
  },
});

// Ao colar, um bloco só entra se o campo o aceita (ou se já estava no texto
// quando ele abriu — ver `pasteable`); num campo sem blocos, nenhum outro
// entra. O HTML colado é lido num documento à parte, que não roda script nem
// carrega imagem.
const stripDisallowedBlocks = (html, labels) => {
  if (!html.includes('data-velk-block')) return html;
  const doc = new DOMParser().parseFromString(html, 'text/html');
  doc.querySelectorAll('div[data-velk-block]').forEach((el) => {
    if (!labels.has(el.getAttribute('data-velk-block'))) {
      el.remove();
      return;
    }
    // O marcador colado entra vazio: o que vier dentro dele (de outra página,
    // de qualquer lugar) iria para o site como veio.
    el.removeAttribute('data-velk-inner');
    el.replaceChildren();
  });
  return doc.body.innerHTML;
};

// Onde um nó novo — um bloco do tema, uma imagem, uma tabela — entra, a partir
// da seleção. Inserir no cursor estraga o que está em volta em dois casos: um
// nó selecionado é trocado pelo novo; e um nó em que o novo não cabe se parte
// para ele entrar — a imagem em cuja legenda está o cursor duplica (ou some,
// se a legenda está vazia), a tabela vira duas. Devolve a posição, ou null
// para inserir no cursor.
//
// `type` é o tipo do nó novo. A imagem e a tabela cabem também num item de
// lista, numa citação e numa célula, e lá ficam. O bloco do tema só existe no
// nível principal do texto, então inserir no cursor parte o que estiver em
// volta: é o esperado no meio de um parágrafo ou de um item de lista. `edges`
// é a regra dele para as pontas de um parágrafo (a última, abaixo).
const insertPosition = (selection, type, { edges = false } = {}) => {
  const { $from } = selection;
  // O nó mais fundo, em volta da seleção, em que o novo cabe: para o bloco do
  // tema, sempre o texto principal (profundidade 0).
  let host = $from.depth;
  while (host > 0) {
    const index = $from.indexAfter(host);
    if ($from.node(host).canReplaceWith(index, index, type)) break;
    host -= 1;
  }
  // Depois (ou antes) do nó da profundidade dada, subindo pela lista ou pela
  // citação enquanto ele for o último (ou o primeiro) filho: no fim de um
  // item do meio a lista se parte entre os itens, sem sobrar item vazio, e no
  // fim do último item o bloco entra depois da lista.
  const after = (depth) => {
    let d = depth;
    while (d > host + 1 && $from.index(d - 1) === $from.node(d - 1).childCount - 1) d -= 1;
    return $from.after(d);
  };
  const before = (depth) => {
    let d = depth;
    while (d > host + 1 && $from.index(d - 1) === 0) d -= 1;
    return $from.before(d);
  };

  // Dentro de uma imagem com legenda — ou, para o bloco, de uma tabela —:
  // depois dela inteira.
  let isolating = 0;
  for (let depth = $from.depth; depth > host; depth -= 1) {
    if ($from.node(depth).type.spec.isolating) isolating = depth;
  }
  if (isolating) return after(isolating);

  // Com um nó selecionado (um bloco, uma imagem), o insertContent e o
  // insertTable o trocariam pelo novo: o novo entra depois dele. Onde não
  // cabe logo depois (o bloco, numa lista), sobe como acima.
  if (selection.node) {
    const index = $from.index() + 1;
    const fits = $from.parent.canReplaceWith(index, index, type);
    const last = index === $from.parent.childCount;
    return !fits && last && $from.depth > 0 ? after($from.depth) : selection.to;
  }

  // Cursor no fim ou no começo de um parágrafo com texto: o bloco entra
  // depois ou antes dele, sem parti-lo. Parágrafo vazio fica com o
  // insertContent, que põe o bloco no lugar dele.
  if (edges && selection.empty && $from.depth > 0 && $from.parent.content.size > 0) {
    if ($from.parentOffset === $from.parent.content.size) return after($from.depth);
    if ($from.parentOffset === 0) return before($from.depth);
  }
  return null;
};

// O cursor de lacuna (entre dois nós onde não cabe cursor de texto) na
// posição dada, ou null quando ali não vale. O pacote do TipTap não exporta a
// classe: ela é alcançada pelo registro de seleções do ProseMirror.
const gapCursorAt = (state, pos) => {
  try {
    const Selection = Object.getPrototypeOf(state.selection.constructor);
    const gap = Selection.fromJSON(state.doc, { type: 'gapcursor', pos });
    return gap.constructor.valid(state.doc.resolve(pos)) ? gap : null;
  } catch {
    return null;
  }
};

// Tira o cursor de cima de um bloco selecionado e o põe depois dele: a
// próxima tecla trocaria o bloco selecionado pelo que fosse digitado.
const cursorAfterBlock = (editor) => {
  const { state } = editor;
  if (!state.selection.node || state.selection.node.type.name !== 'velkBlock') return;
  const end = state.selection.to;
  if (!state.doc.resolve(end).nodeAfter) {
    // Fim do texto: um parágrafo novo (se ficar vazio, não é gravado).
    editor.chain().insertContentAt(end, { type: 'paragraph' }).setTextSelection(end + 1).run();
    return;
  }
  // Tabela, imagem, linha ou outro bloco embaixo: a lacuna entre os dois.
  // Digitar ali abre um parágrafo, e outro bloco entra ao lado deste.
  const gap = gapCursorAt(state, end);
  if (gap) {
    editor.commands.command(({ tr }) => {
      tr.setSelection(gap);
      return true;
    });
    return;
  }
  // Parágrafo, título, lista ou citação embaixo: o começo do texto dele.
  let text = null;
  state.doc.nodesBetween(end, state.doc.content.size, (node, pos) => {
    if (text === null && node.isTextblock) text = pos + 1;
    return text === null;
  });
  if (text !== null) editor.commands.setTextSelection(text);
};

// Põe um bloco novo no texto e deixa o cursor depois dele.
const insertBlock = (editor, attrs) => {
  const block = { type: 'velkBlock', attrs };
  const position = () => insertPosition(editor.state.selection, editor.schema.nodes.velkBlock, { edges: true });
  let at = position();
  // Um trecho de texto selecionado sai antes, e o bloco entra onde ele
  // estava: um parágrafo selecionado inteiro dá lugar ao bloco, sem sobrar um
  // parágrafo vazio.
  if (at === null && !editor.state.selection.empty && editor.state.selection.toJSON().type === 'text') {
    editor.chain().focus().deleteSelection().run();
    at = position();
  }
  if (at === null) {
    editor.chain().focus().insertContent(block).run();
  } else {
    editor.chain().focus().insertContentAt(at, block).run();
  }
  cursorAfterBlock(editor);
  // A tela vai até o cursor, logo depois do cartão novo: o cursor pode estar
  // fora dela (o autor rolou o texto antes de abrir a janela), e inserir não
  // rola a tela sozinho.
  editor.commands.scrollIntoView();
};

// O começo dos comandos de um botão da barra que insere uma imagem ou uma
// tabela. O cursor fica onde está, a não ser que ali o nó novo estragasse o que
// está em volta (ver insertPosition): aí abre-se uma linha vazia no lugar
// certo, com o cursor nela. O comando que vem em seguida (insertContent,
// insertTable) põe o nó novo no lugar dessa linha, como faz com qualquer linha
// vazia, e os dois saem juntos num passo só do desfazer.
const insertChain = (editor, name) => {
  const chain = editor.chain().focus();
  const at = insertPosition(editor.state.selection, editor.schema.nodes[name]);
  return at === null ? chain : chain.insertContentAt(at, { type: 'paragraph' });
};

// Figure com legenda vazia sai do editor como <img> puro. Atributos vêm
// sempre double-quoted do serializer e podem conter ">" (ex.: alt="a > b") —
// daí o grupo ciente de aspas em vez de [^>]*. Legenda só com <br>
// (Shift+Enter sem texto) também conta como vazia; <br> no meio de texto
// (legenda multi-linha) é preservado. O replace é seguro porque a string vem
// do renderHTML acima (determinística), não de HTML arbitrário do usuário.
//
// Um bloco do tema inserido no fim do texto ganha um parágrafo vazio depois
// dele, para o cursor ter onde ficar (ver cursorAfterBlock). Se ninguém
// escrever ali, ele não vai para o HTML gravado.
//
// O conteúdo que estava dentro de um marcador de bloco (atributo `inner` do
// nó) sai do atributo data-velk-inner e volta para dentro da tag. O valor é
// decodificado num <textarea> solto: o texto vira texto, nada é montado nem
// carregado na página.
const decodeAttr = (value) => {
  const area = document.createElement('textarea');
  area.innerHTML = value;
  return area.value;
};
const cleanRichHtml = (html) => html
  .replace(
    /<figure><img((?:[^>"]|"[^"]*")*)><figcaption>(?:<br>)*<\/figcaption><\/figure>/g,
    '<img$1>'
  )
  .replace(/(<div data-velk-block="[^"]*" data-velk-data="[^"]*"(?: data-velk-inner="[^"]*")?><\/div>)(?:<p><\/p>)+$/, '$1')
  .replace(/<div (data-velk-block="[^"]*" data-velk-data="[^"]*") data-velk-inner="([^"]*)"><\/div>/g,
    (match, attrs, inner) => `<div ${attrs}>${decodeAttr(inner)}</div>`);

const initRichText = (root) => {
  const editorEl = root.querySelector('[data-richtext-editor]');
  const sourceEl = root.querySelector('.richtext__source');
  const toolbarEl = root.querySelector('[data-richtext-toolbar]');
  if (!editorEl || !sourceEl) return;

  // Os blocos do tema que este campo aceita, entregues pelo servidor
  // (FieldRenderer): a lista, o endereço da janela e o token de CSRF.
  let blocks = [];
  try { blocks = JSON.parse(root.dataset.blocks || '[]'); } catch { blocks = []; }
  const blockLabels = new Map(blocks.map((b) => [b.key, b.label]));
  // O que pode ser colado: os blocos do campo e, logo depois de o editor
  // nascer, os tipos que já estavam no texto. Sem estes, recortar e colar um
  // bloco indisponível — o jeito de movê-lo — o apagaria com os dados.
  const pasteable = new Set(blockLabels.keys());
  const blocksUrl = root.dataset.blocksUrl || '';
  const blocksCsrf = root.dataset.blocksCsrf || '';

  // Abre a janela do bloco: a escolha (sem `type`) ou o formulário dele. A
  // janela devolve `{type, data}` à `__velkBlockCallback` desta página — o
  // mesmo caminho da biblioteca de mídia.
  const openBlockWindow = (type, data, onSaved) => {
    const onResult = (result) => {
      if (!result || typeof result.type !== 'string') return;
      // O resultado nasce na página de dentro: guardado como veio, manteria o
      // documento da janela vivo depois de ela fechar. Entra uma cópia.
      const saved = JSON.parse(JSON.stringify(result));
      modal.close();
      onSaved(saved);
    };
    window.__velkBlockCallback = onResult;
    const fields = [['_csrf', blocksCsrf], ['type', type]];
    if (data) fields.push(['data', JSON.stringify(data)]);
    const modal = openModal({
      title: blockLabels.get(type) || type,
      post: { action: `${blocksUrl}/form`, fields },
      compact: true,
      onClose: () => {
        if (window.__velkBlockCallback === onResult) delete window.__velkBlockCallback;
        // Na janela de um cartão, os dois cliques que a abrem deixam o cartão
        // selecionado (o botão Editar não), e a tecla seguinte o trocaria
        // pelo que fosse digitado: o cursor sai de cima dele, salvando ou não.
        // Sem cartão selecionado, não faz nada.
        cursorAfterBlock(editor);
        // O editor perdeu o foco ao abrir a janela: volta para ele, com o
        // cursor onde estava.
        editor.view.focus();
      },
    });
  };

  const editBlock = (node, getPos) => {
    openBlockWindow(node.attrs.type, node.attrs.data, (result) => {
      const attrs = { type: result.type, data: result.data };
      // O cartão pode ter saído do texto enquanto a janela estava aberta:
      // o bloco salvo entra como novo, em vez de se perder.
      const pos = getPos();
      const current = typeof pos === 'number' ? editor.state.doc.nodeAt(pos) : null;
      if (!current || current.type.name !== 'velkBlock') {
        insertBlock(editor, attrs);
        return;
      }
      editor.chain().focus().command(({ tr }) => {
        tr.setNodeMarkup(pos, undefined, attrs);
        return true;
      }).setNodeSelection(pos).run();
      cursorAfterBlock(editor);
    });
  };

  // O que cobre o texto nas bordas da tela: em cima, a barra do editor quando
  // está grudada; embaixo, a barra de salvar. O editor consulta estas medidas
  // toda vez que leva a tela até o cursor (são getters: as barras mudam de
  // lugar e de altura) — sem elas, o cursor pararia atrás de uma das duas.
  // `gap` é a folga entre o cursor e a barra. A barra de salvar é a da tela,
  // e não a do <form> do campo: na edição de página ela fica fora dele.
  const saveBar = document.querySelector('.form-actions');
  const covered = (gap) => ({
    get top() {
      return Math.max(0, toolbarEl?.getBoundingClientRect().bottom ?? 0) + gap;
    },
    get bottom() {
      const bar = saveBar?.getBoundingClientRect();
      const height = document.documentElement.clientHeight;
      return (bar?.height ? Math.max(0, height - bar.top) : 0) + gap;
    },
    left: gap,
    right: gap,
  });

  const editor = new Editor({
    element: editorEl,
    extensions: [
      StarterKit.configure({ document: false }),
      VelkDocument,
      Link.configure({ openOnClick: false, HTMLAttributes: { rel: 'noopener noreferrer' } }),
      Figure,
      createVelkBlock({ labels: blockLabels, onEdit: editBlock }),
      Table.configure({ resizable: true }),
      TableRow,
      TableHeader,
      TableCell,
    ],
    editorProps: {
      transformPastedHTML: (html) => stripDisallowedBlocks(html, pasteable),
      scrollThreshold: covered(0),
      scrollMargin: covered(5),
    },
    content: sourceEl.value || '',
    onUpdate: ({ editor }) => {
      sourceEl.value = cleanRichHtml(editor.getHTML());
    },
    onSelectionUpdate: () => updateToolbarState(),
  });
  editor.state.doc.descendants((node) => {
    if (node.type.name === 'velkBlock') pasteable.add(node.attrs.type);
  });

  // Click anywhere inside the wrapper that ISN'T the contenteditable surface
  // (e.g. the small gap between the toolbar bottom border and the .ProseMirror
  // top, or any future padding) should still focus the editor at end. This
  // makes the whole control feel like a single text input.
  root.addEventListener('mousedown', (e) => {
    const target = e.target;
    if (target.closest('[data-richtext-toolbar], button, a, input, textarea, select')) {
      return;
    }
    if (target.closest('.ProseMirror')) {
      return; // ProseMirror handles its own focus correctly
    }
    e.preventDefault();
    editor.commands.focus('end');
  });

  const updateToolbarState = () => {
    if (!toolbarEl) return;
    toolbarEl.querySelectorAll('button[data-cmd]').forEach((btn) => {
      const cmd = btn.dataset.cmd;
      let active = false;
      switch (cmd) {
        case 'bold': active = editor.isActive('bold'); break;
        case 'italic': active = editor.isActive('italic'); break;
        case 'strike': active = editor.isActive('strike'); break;
        case 'paragraph': active = editor.isActive('paragraph'); break;
        case 'h2': active = editor.isActive('heading', { level: 2 }); break;
        case 'h3': active = editor.isActive('heading', { level: 3 }); break;
        case 'bulletList': active = editor.isActive('bulletList'); break;
        case 'orderedList': active = editor.isActive('orderedList'); break;
        case 'blockquote': active = editor.isActive('blockquote'); break;
        case 'code': active = editor.isActive('code'); break;
        case 'codeBlock': active = editor.isActive('codeBlock'); break;
        case 'block': {
          // Aceso enquanto a faixa dos blocos estiver aberta.
          active = toolbarEl.querySelector('[data-block-tools]')?.hidden === false;
          btn.setAttribute('aria-expanded', String(active));
          break;
        }
      }
      btn.classList.toggle('is-active', active);
    });
    // As operações da tabela (linha, coluna, apagar) ficam numa faixa abaixo
    // da barra, só com o cursor numa tabela. O botão "Tabela", que a cria,
    // fica sempre na barra.
    const tableTools = toolbarEl.querySelector('[data-table-tools]');
    if (tableTools) tableTools.hidden = !editor.isActive('table');
  };

  // Um texto que começa por um bloco abriria com esse bloco selecionado (a
  // seleção inicial do editor cai no primeiro nó), e a primeira tecla o
  // trocaria sem ninguém o ter escolhido. O cursor começa no primeiro texto;
  // num texto só de blocos, antes do primeiro.
  const opening = editor.state.selection;
  if (opening.node && opening.node.type.name === 'velkBlock') {
    let text = null;
    editor.state.doc.descendants((node, pos) => {
      if (text === null && node.isTextblock) text = pos + 1;
      return text === null;
    });
    const gap = text === null ? gapCursorAt(editor.state, 0) : null;
    if (text !== null) {
      editor.commands.setTextSelection(text);
    } else if (gap) {
      editor.commands.command(({ tr }) => {
        tr.setSelection(gap);
        return true;
      });
    }
  }

  if (toolbarEl) {
    toolbarEl.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-cmd]');
      if (!btn) return;
      e.preventDefault();
      const cmd = btn.dataset.cmd;
      const chain = editor.chain().focus();
      switch (cmd) {
        case 'bold': chain.toggleBold().run(); break;
        case 'italic': chain.toggleItalic().run(); break;
        case 'strike': chain.toggleStrike().run(); break;
        case 'paragraph': chain.setParagraph().run(); break;
        case 'h2': chain.toggleHeading({ level: 2 }).run(); break;
        case 'h3': chain.toggleHeading({ level: 3 }).run(); break;
        case 'bulletList': chain.toggleBulletList().run(); break;
        case 'orderedList': chain.toggleOrderedList().run(); break;
        case 'blockquote': chain.toggleBlockquote().run(); break;
        case 'link': {
          const url = window.prompt(t('js_link_prompt', 'Link URL:'));
          if (url === null) return;
          if (url === '') {
            chain.unsetLink().run();
          } else {
            chain.setLink({ href: url }).run();
          }
          break;
        }
        case 'image': {
          // Abre a biblioteca de mídia (mesmo picker do campo de imagem,
          // filtrado a imagens) e insere a escolhida no cursor como Figure
          // (legenda opcional embaixo). Single-pick: escolher fecha o modal.
          // O alt vem da própria mídia (gerenciado na biblioteca), sem prompt
          // extra. Chain nova dentro do callback: a `chain` de cima é
          // síncrona e já expirou quando o picker volta. Pelo insertChain, a
          // imagem nova não troca uma imagem ou um bloco selecionados, nem a
          // imagem em cuja legenda está o cursor (logo depois de inserir uma,
          // é lá que ele fica): entra depois. Um trecho de texto ou células
          // selecionadas são trocados, como em qualquer inserção.
          const modal = openMediaPicker({
            title: t('js_media_library_title', 'Media library'),
            src: `${ADMIN_BASE}/media?picker=1&accept=image`,
            onPick: (data) => {
              if (!data || data.is_image === false || !data.url) return;
              insertChain(editor, 'figure')
                .insertContent({ type: 'figure', attrs: { src: data.url, alt: data.alt || '' } })
                .run();
              modal.close();
              // A tela vai até o cursor, logo depois da imagem (como no bloco:
              // inserir não rola a tela sozinho) — e de novo quando ela
              // carregar: só então a imagem ganha altura e empurra o cursor.
              editor.commands.scrollIntoView();
              [...editorEl.querySelectorAll('img')]
                .find((img) => !img.complete && img.getAttribute('src') === data.url)
                ?.addEventListener('load', () => editor.commands.scrollIntoView(), { once: true });
            },
            // Como na janela do bloco: o foco volta para o editor.
            onClose: () => editor.view.focus(),
          });
          break;
        }
        case 'block': {
          // Abre e fecha a faixa dos blocos, embaixo da barra.
          const row = toolbarEl.querySelector('[data-block-tools]');
          if (row) row.hidden = !row.hidden;
          break;
        }
        case 'insertBlock': {
          // Um bloco da faixa: ela fecha, e a janela abre já no formulário
          // dele. Chain nova dentro do callback, como na imagem: a de cima
          // já expirou quando a janela devolve o bloco.
          const row = toolbarEl.querySelector('[data-block-tools]');
          if (row) row.hidden = true;
          openBlockWindow(btn.dataset.block, null, (result) => {
            insertBlock(editor, { type: result.type, data: result.data });
          });
          break;
        }
        case 'code': chain.toggleCode().run(); break;
        case 'codeBlock': chain.toggleCodeBlock().run(); break;
        // Como a imagem: a tabela nova não troca o que está selecionado.
        case 'insertTable': insertChain(editor, 'table').insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(); break;
        case 'addRowAfter': chain.addRowAfter().run(); break;
        case 'deleteRow': chain.deleteRow().run(); break;
        case 'addColumnAfter': chain.addColumnAfter().run(); break;
        case 'deleteColumn': chain.deleteColumn().run(); break;
        case 'deleteTable': chain.deleteTable().run(); break;
        case 'undo': chain.undo().run(); break;
        case 'redo': chain.redo().run(); break;
      }
      updateToolbarState();
    });
  }

  // Keep textarea in sync on form submit (defensive)
  const form = root.closest('form');
  if (form) {
    form.addEventListener('submit', () => {
      sourceEl.value = cleanRichHtml(editor.getHTML());
    });
  }

  updateToolbarState();
};

// Reordenação por arrastar de linhas verticais (repeater/flexible).
// Cada row recebe seus próprios handlers; a reordenação acontece no
// `drop` da row-alvo (insert por midpoint-Y). `onReorder` roda após
// mover (cada campo passa o seu reindex). Único lugar dessa lógica —
// flexible e repeater compartilham, evitando a divergência que já
// causou bug (admin.js v2.1.1).
const makeRowsSortable = (rowsEl, { onReorder }) => {
  let draggedRow = null;

  const bindRow = (row) => {
    row.addEventListener('dragstart', (e) => {
      // Um nó arrastável do editor de texto (o cartão de um bloco) é movido
      // pelo próprio editor: cancelar aqui também cancelaria o arrasto dele.
      if (e.target.closest('.ProseMirror [draggable="true"]')) return;
      const interactiveTags = 'input, textarea, select, button, [contenteditable="true"], .richtext__editor';
      if (e.target.closest(interactiveTags)) {
        e.preventDefault();
        return;
      }
      if (e.target.closest('[draggable="true"]') !== row) {
        return;
      }
      draggedRow = row;
      row.classList.add('is-dragging');
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
      }
    });

    row.addEventListener('dragend', () => {
      row.classList.remove('is-dragging');
      draggedRow = null;
      rowsEl.querySelectorAll('.is-drag-over').forEach((el) => el.classList.remove('is-drag-over'));
    });

    row.addEventListener('dragover', (e) => {
      if (!draggedRow || draggedRow === row) return;
      e.preventDefault();
      row.classList.add('is-drag-over');
    });

    row.addEventListener('dragleave', () => {
      row.classList.remove('is-drag-over');
    });

    row.addEventListener('drop', (e) => {
      e.preventDefault();
      row.classList.remove('is-drag-over');
      if (!draggedRow || draggedRow === row) return;
      const rect = row.getBoundingClientRect();
      const midpoint = rect.top + rect.height / 2;
      if (e.clientY < midpoint) {
        row.parentNode.insertBefore(draggedRow, row);
      } else {
        row.parentNode.insertBefore(draggedRow, row.nextSibling);
      }
      onReorder();
    });
  };

  return { bindRow };
};

// Valor de um campo para comparação (não para exibição — isso é readFieldText).
// Select devolve o VALUE, não o rótulo; boolean devolve true/false.
const readFieldValue = (fieldEl) => {
  if (!fieldEl) return null;
  const type = fieldEl.dataset.fieldType || 'text';
  if (type === 'boolean') return !!fieldEl.querySelector('input[type="checkbox"]')?.checked;
  if (type === 'select') return fieldEl.querySelector('select')?.value ?? null;
  const input = fieldEl.querySelector('input, textarea');
  return input ? input.value : null;
};

// Campos condicionais (`show_if` no site.json).
//
// O servidor já renderiza no estado certo; isto mantém em dia enquanto a
// pessoa mexe. Espelha FieldRenderer::matchesShowIf() — divergir faria o
// campo aparecer ao digitar e sumir ao recarregar.
const initConditionalField = (fieldEl) => {
  let cond;
  try { cond = JSON.parse(fieldEl.dataset.showIf); } catch (e) { return; }
  if (!cond || typeof cond.field !== 'string' || !cond.field) return;

  // Escopo: a linha de repetidor mais próxima, ou o formulário. É o mesmo
  // array plano de valores que o PHP avaliou — e a comparação por `closest`
  // impede capturar um campo de mesmo nome de um repetidor ANINHADO.
  const scope = fieldEl.closest('[data-repeater-row]') || fieldEl.closest('form') || document;
  const controller = [...scope.querySelectorAll(`[data-field-name="${CSS.escape(cond.field)}"]`)]
    .find((el) => (el.closest('[data-repeater-row]') || el.closest('form') || document) === scope);
  if (!controller) return;

  // Mesma tolerância do looseEquals no PHP: booleano normalizado, resto
  // comparado como string. `===` faria `equals: true` falhar num checkbox.
  const eq = (current, expected) => {
    if (typeof expected === 'boolean') return expected === !!current;
    if (current === null || current === undefined) return expected === null || expected === '';
    return String(current) === String(expected);
  };

  const sync = () => {
    const current = readFieldValue(controller);
    let visible = true;
    if (Array.isArray(cond.in)) visible = cond.in.some((v) => eq(current, v));
    else if ('equals' in cond) visible = eq(current, cond.equals);

    fieldEl.hidden = !visible;
    // `required` acompanha a visibilidade: obrigatório invisível trava o
    // submit sem nada na tela explicando por quê.
    if (fieldEl.dataset.requiredWhenVisible) {
      fieldEl.querySelectorAll('input, textarea, select').forEach((el) => {
        if (visible) el.setAttribute('required', '');
        else el.removeAttribute('required');
      });
    }
  };

  controller.addEventListener('input', sync);
  controller.addEventListener('change', sync);
  sync();
};

// Checkbox que revela outro bloco quando marcado.
//
//   <input type="checkbox" data-reveal-target="#consent-category">
//   <div id="consent-category" hidden>…</div>
//
// O servidor já renderiza no estado certo (o `hidden` sai do PHP), então isto
// só cuida da interação — sem JS o bloco fica no estado que ele tinha ao
// carregar, que é o correto para o valor salvo.
const initRevealToggle = (input) => {
  const target = document.querySelector(input.dataset.revealTarget);
  if (!target) return;
  const sync = () => { target.hidden = !input.checked; };
  input.addEventListener('change', sync);
  sync();
};

// Tabela com linhas reordenáveis por arrasto, persistindo no servidor.
//
// Genérico de propósito: qualquer tela que liste linhas do BANCO com ordem
// própria usa isto (scripts, items e termos). O repetidor não serve para
// esse caso — lá a ordem viaja no submit do formulário, aqui não há
// formulário e a ordem precisa gravar sozinha.
//
// Contrato no HTML:
//
//   <tbody data-sortable-table
//          data-sortable-endpoint="/admin/scripts/reorder"
//          data-csrf="...">
//     <tr draggable="true" data-sortable-id="7">…</tr>
//
// UM tbody por grupo que tenha ordem independente. Nos scripts é um por
// posição (head/body_start/body_end), o que também impede arrastar uma linha
// para um grupo ao qual ela não pertence: sem isso a linha voltaria ao lugar
// no próximo carregamento, sem explicação.
const initSortableTable = (tbody) => {
  const endpoint = tbody.dataset.sortableEndpoint;
  const csrf = tbody.dataset.csrf;
  if (!endpoint || !csrf) return;

  const rows = () => [...tbody.querySelectorAll(':scope > [data-sortable-id]')];

  const persist = async () => {
    const fd = new FormData();
    fd.append('_csrf', csrf);
    rows().forEach((r) => fd.append('ids[]', r.dataset.sortableId));

    tbody.classList.add('is-saving');
    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      // Falha aqui é silenciosa no DOM mas NÃO no servidor: a ordem na tela
      // ficaria diferente da gravada, e o próximo carregamento desfaz o
      // arrasto sem avisar. Melhor recarregar e mostrar a verdade.
      if (!res.ok) window.location.reload();
    } catch (e) {
      window.location.reload();
    } finally {
      tbody.classList.remove('is-saving');
    }
  };

  const sortable = makeRowsSortable(tbody, { onReorder: persist });
  rows().forEach(sortable.bindRow);
};

// Texto legível de um campo, para rotular a linha de um repetidor
// (`title_field`). Espelha FieldRenderer::rowTitle() no servidor: aquele
// desenha o rótulo inicial, este o mantém em dia enquanto a pessoa digita.
// Divergir os dois faria o rótulo mudar sozinho ao recarregar a página.
const readFieldText = (fieldEl) => {
  if (!fieldEl) return '';
  const type = fieldEl.dataset.fieldType || 'text';

  if (type === 'link') {
    // Título do link, caindo na URL quando ele está em branco — um link sem
    // título ainda identifica a linha melhor que "ITEM 03".
    const title = fieldEl.querySelector('input[name$="[title]"]');
    const url = fieldEl.querySelector('input[name$="[url]"]');
    return (title?.value || '').trim() || (url?.value || '').trim();
  }

  if (type === 'select') {
    // O RÓTULO da opção, não o valor: `{"br": "Brasil"}` mostra "Brasil".
    const sel = fieldEl.querySelector('select');
    return (sel?.selectedOptions?.[0]?.text || '').trim();
  }

  const input = fieldEl.querySelector('input, textarea');
  return (input?.value || '').trim();
};

// Igual a FieldRenderer::ROW_TITLE_MAX_LENGTH. O CSS ainda aplica ellipsis;
// o corte aqui evita carregar um parágrafo inteiro no DOM do cabeçalho.
const ROW_TITLE_MAX_LENGTH = 80;

const initRepeater = (root) => {
  // `:scope >` restricts to direct children. Without it, when a repeater
  // contains a NESTED repeater inside one of its rows, the unscoped
  // querySelector picks up the nested repeater's template/add button
  // (they come earlier in document order than the outer template/button,
  // which sit AFTER [data-repeater-rows]). The outer "add" button would
  // silently stop working and the nested button would receive two
  // handlers. The FieldRenderer guarantees rows/template/button are
  // direct children of [data-repeater], so `:scope >` is unambiguous.
  // The rest of the function already uses `:scope >` correctly — these
  // three initial lookups were the inconsistency.
  const rowsEl = root.querySelector(':scope > [data-repeater-rows]');
  const tplEl = root.querySelector(':scope > [data-repeater-template]');
  const addBtn = root.querySelector(':scope > [data-repeater-add]');
  if (!rowsEl || !tplEl || !addBtn) return;

  // Template is base64-encoded server-side so nested HTML (inputs, even
  // nested repeaters) survives intact and __INDEX__ stays unescaped.
  // decodeBase64Utf8 properly handles multi-byte UTF-8 — bypassing it
  // with raw atob() would mojibake accents and any non-ASCII chars.
  let templateHtml = '';
  try {
    templateHtml = decodeBase64Utf8((tplEl.textContent || '').trim());
  } catch (e) {
    console.warn('Repeater template decode failed', e);
    return;
  }

  const reindex = () => {
    rowsEl.querySelectorAll(':scope > [data-repeater-row]').forEach((row, idx) => {
      row.querySelectorAll('[name]').forEach((el) => {
        // Only rewrite the OUTER index of this repeater — leave nested ones alone.
        const prefix = root.dataset.repeaterName;
        const re = new RegExp('^(' + prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')\\[(\\d+|__INDEX__)\\]');
        el.name = el.name.replace(re, `$1[${idx}]`);
      });
    });
  };

  const sortable = makeRowsSortable(rowsEl, { onReorder: reindex });

  // `title_field` do site.json. Ausente = linhas seguem numeradas pelo
  // contador de CSS, e nada abaixo roda.
  const titleField = root.dataset.repeaterTitleField || '';

  const bindRowTitle = (row) => {
    if (!titleField) return;
    const titleEl = row.querySelector(':scope > [data-repeater-row-header] [data-repeater-row-title]');
    if (!titleEl) return;

    // Escopo pelo `closest`, não por `:scope >`: o campo pode estar dentro de
    // um group/tabs (contêineres transparentes, que gravam no mesmo array
    // plano da linha), então a profundidade é imprevisível. O que NÃO pode é
    // capturar um campo de mesmo nome de um repetidor ANINHADO — daí a
    // comparação da linha mais próxima.
    const fieldEl = [...row.querySelectorAll(`[data-field-name="${CSS.escape(titleField)}"]`)]
      .find((el) => el.closest('[data-repeater-row]') === row);
    if (!fieldEl) return;

    const sync = () => {
      // Vazio devolve o contador do CSS (`:empty`) — por isso limpamos o
      // textContent em vez de escrever um placeholder.
      titleEl.textContent = readFieldText(fieldEl).slice(0, ROW_TITLE_MAX_LENGTH);
    };

    // `input` cobre digitação; `change` cobre select e autofill.
    fieldEl.addEventListener('input', sync);
    fieldEl.addEventListener('change', sync);
    sync();
  };

  const bindRow = (row) => {
    sortable.bindRow(row);
    bindRowTitle(row);

    // Remove button lives in the row header now (mirrors the flexible
    // widget). `:scope >` chains keep nested repeaters' buttons from
    // being captured by the outer row — same hijack-lookup lesson as
    // initRepeater's own element resolution.
    const removeBtn = row.querySelector(':scope > [data-repeater-row-header] [data-repeater-remove]');
    if (removeBtn) {
      removeBtn.addEventListener('click', () => {
        row.remove();
        reindex();
      });
    }

    // Collapse / expand — the WHOLE header is the click target (title,
    // empty space, anywhere), so users don't have to aim at the tiny
    // chevron. Only the remove button is excluded; the chevron button
    // also falls through to here (it's a button), so its own handler
    // below stays the single source of the toggle.
    const toggleBtn = row.querySelector(':scope > [data-repeater-row-header] [data-repeater-toggle]');
    toggleBtn?.addEventListener('click', () => {
      row.classList.toggle('is-collapsed');
    });
    const header = row.querySelector(':scope > [data-repeater-row-header]');
    header?.addEventListener('click', (e) => {
      if (e.target.closest('button')) return;
      if (e.target.closest('.repeater__row-handle')) return;
      row.classList.toggle('is-collapsed');
    });
  };

  addBtn.addEventListener('click', () => {
    const idx = rowsEl.querySelectorAll(':scope > [data-repeater-row]').length;
    const html = templateHtml.replace(/__INDEX__/g, idx.toString());
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    const newRow = wrapper.firstElementChild;
    if (newRow) {
      rowsEl.appendChild(newRow);
      bindRow(newRow);
      // Init any nested widgets inside the freshly inserted row
      newRow.querySelectorAll('[data-richtext]').forEach(initRichText);
      newRow.querySelectorAll('[data-repeater]').forEach(initRepeater);
      newRow.querySelectorAll('[data-image-field]').forEach(initImageField);
      newRow.querySelectorAll('[data-file-field]').forEach(initFileField);
      newRow.querySelectorAll('[data-item-field]').forEach(initItemField);
      newRow.querySelectorAll('[data-gallery-field]').forEach(initGalleryField);
      newRow.querySelectorAll('[data-flexible-field]').forEach(initFlexibleField);
      newRow.querySelectorAll('[data-show-if]').forEach(initConditionalField);
    }
  });

  rowsEl.querySelectorAll(':scope > [data-repeater-row]').forEach(bindRow);
};

const initSlugify = () => {
  const titleInput = document.querySelector('[data-slug-source]');
  const slugInput = document.querySelector('[data-slug-target]');
  if (!titleInput || !slugInput) return;

  let manuallyEdited = slugInput.value.trim() !== '';
  slugInput.addEventListener('input', () => { manuallyEdited = true; });

  titleInput.addEventListener('input', () => {
    if (manuallyEdited) return;
    slugInput.value = slugify(titleInput.value);
  });
};

const slugify = (str) => {
  return str
    .toString()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '') || 'item';
};

// ── Bulk actions (items/terms list — bulk delete) ───────────────────────────
// Toggled purely client-side: `data-bulk-toggle` (icon button — in the
// filter bar on items, in the header on terms) flips `is-bulk-active` on the
// `[data-bulk-list]` container that wraps the action bar + table; CSS
// reveals the checkbox column and the bar (see admin.css). The checkboxes
// live inside the table but submit through #bulk-delete-form, which sits
// OUTSIDE the table (HTML5 `form="bulk-delete-form"` association) — that's
// what keeps the existing per-row delete <form> from ever nesting inside a
// table-wide form (see tests/unit/admin_no_nested_forms_test.php). At most
// one `[data-bulk-list]` exists per page (items/index or terms/index), so
// this is a page-level singleton, not a repeating-widget init like the
// others above.
const initBulkActions = () => {
  const toggleBtn = document.querySelector('[data-bulk-toggle]');
  const root = document.querySelector('[data-bulk-list]');
  if (!toggleBtn || !root) return;

  const selectAll = root.querySelector('[data-bulk-select-all]');
  const submitBtn = root.querySelector('[data-bulk-submit]');
  const countEl = root.querySelector('[data-bulk-count]');
  const cancelBtn = root.querySelector('[data-bulk-cancel]');
  const checkboxes = () => Array.from(root.querySelectorAll('[data-bulk-checkbox]'));

  const updateCount = () => {
    const boxes = checkboxes();
    const n = boxes.filter((cb) => cb.checked).length;
    if (countEl) {
      countEl.textContent = n === 1
        ? t('js_bulk_selected_one', '1 selected')
        : t('js_bulk_selected_other', '{count} selected').replace('{count}', String(n));
    }
    if (submitBtn) submitBtn.disabled = n === 0;
    if (selectAll) {
      selectAll.checked = boxes.length > 0 && n === boxes.length;
      selectAll.indeterminate = n > 0 && n < boxes.length;
    }
  };

  const setActive = (active) => {
    root.classList.toggle('is-bulk-active', active);
    // O botão vive FORA de `root` (barra de filtros), então não herda a
    // classe. Sem isto, quem liga o modo não tem como ver por qual botão
    // desligar — a barra de seleção some no scroll de uma lista longa.
    toggleBtn.classList.toggle('is-active', active);
    toggleBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
    if (!active) {
      checkboxes().forEach((cb) => { cb.checked = false; });
      if (selectAll) selectAll.indeterminate = false;
    }
    updateCount();
  };

  toggleBtn.addEventListener('click', () => setActive(!root.classList.contains('is-bulk-active')));
  cancelBtn?.addEventListener('click', () => setActive(false));

  selectAll?.addEventListener('change', () => {
    checkboxes().forEach((cb) => { cb.checked = selectAll.checked; });
    updateCount();
  });

  // Delegated: covers every row checkbox without one listener per row (and
  // rows never get added/removed client-side here, but this stays cheap
  // and consistent with the delegated patterns used elsewhere in this file).
  root.addEventListener('change', (e) => {
    if (e.target instanceof Element && e.target.matches('[data-bulk-checkbox]')) updateCount();
  });

  updateCount();
};

const initConfirms = () => {
  document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      const message = form.dataset.confirm || t('js_confirm_generic', 'Are you sure?');
      if (!window.confirm(message)) {
        e.preventDefault();
      }
    });
  });
};

// Auto-submit a select's parent <form> on change. Used for the table
// toolbar filters (status, etc.) so changing a dropdown reloads the list
// without a separate "Apply" button.
const initAutosubmit = () => {
  document.querySelectorAll('[data-autosubmit]').forEach((el) => {
    el.addEventListener('change', () => {
      const form = el.closest('form');
      if (form) form.submit();
    });
  });
};

// ── Abas de idioma (options, items, pages, terms) ──────────────────────────
// Clicar numa aba mostra o painel daquele idioma e esconde os outros.
const initLangTabs = () => {
  document.querySelectorAll('[data-lang-tabs]').forEach((tabsEl) => {
    const panels = tabsEl.parentElement.querySelectorAll('[data-lang-panel]');
    tabsEl.querySelectorAll('[data-lang-tab]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const lang = btn.dataset.langTab;
        tabsEl.querySelectorAll('[data-lang-tab]').forEach((b) => b.classList.toggle('is-active', b === btn));
        panels.forEach((p) => {
          const active = p.dataset.langPanel === lang;
          p.classList.toggle('is-active', active);
          p.hidden = !active;
        });
      });
    });
  });
};

// ── Tabela de traduções do tema (theme-strings) ────────────────────────────
// Edição no lugar: ao sair do campo, grava um (id, idioma, valor) em
// /admin/translations/{id}/update, se o valor mudou.
const initTranslationsTable = () => {
  document.querySelectorAll('.translations-table').forEach((table) => {
    const csrf = table.dataset.csrf;
    const urlTemplate = table.dataset.updateUrl;

    table.querySelectorAll('.ts-input').forEach((input) => {
      input.addEventListener('blur', async () => {
        const original = input.dataset.originalValue;
        const value = input.value;
        if (original === value) return;

        const row = input.closest('tr');
        const cell = input.closest('.ts-cell');
        const id = row.dataset.stringId;
        const lang = cell.dataset.lang;
        const url = urlTemplate.replace('__ID__', id);
        const saved = cell.querySelector('.ts-saved');

        try {
          const fd = new FormData();
          fd.append('_csrf', csrf);
          fd.append('language', lang);
          fd.append('translation', value);
          const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
          if (!res.ok) throw new Error('save failed');

          input.dataset.originalValue = value;
          cell.classList.toggle('ts-cell--pending', value === '');
          saved.hidden = false;
          setTimeout(() => { saved.hidden = true; }, 1500);
        } catch (e) {
          input.style.outline = '1px solid #dc2626';
          setTimeout(() => { input.style.outline = ''; }, 2000);
        }
      });
    });
  });
};

// ── Modal helper ────────────────────────────────────────────────────────────
// `src` abre a janela por GET. `post` ({action, fields: [[nome, valor], …]})
// a abre por POST — um formulário oculto apontado para o iframe —, para
// quando o que a janela precisa receber não cabe numa URL.
// Janela compacta: a altura do iframe acompanha a página de dentro (mesma
// origem), até o teto do CSS — passou disso, quem rola é a janela. A página de
// dentro não abre janelas dela: a biblioteca de mídia abre na página de cima,
// por cima desta (openMediaPicker). Devolve o que desliga a escuta.
const fitModalToContent = (modal, iframe) => {
  let observers = [];
  const disconnect = () => {
    observers.forEach((o) => o.disconnect());
    observers = [];
  };
  const fit = () => {
    const doc = iframe.contentDocument;
    const main = doc?.querySelector('.embed__main');
    if (!main) return;
    iframe.style.height = `${Math.ceil(main.getBoundingClientRect().height)}px`;
  };
  // A cada página carregada no iframe (escolha → formulário → resposta), um
  // documento novo: os observadores são do window dele.
  iframe.addEventListener('load', () => {
    disconnect();
    const win = iframe.contentWindow;
    const doc = iframe.contentDocument;
    const main = doc?.querySelector('.embed__main');
    if (!win || !main) return;
    const resize = new win.ResizeObserver(fit);
    resize.observe(main);
    const inner = new win.MutationObserver(fit);
    inner.observe(doc.body, { childList: true });
    observers = [resize, inner];
    fit();
  });
  return disconnect;
};

const openModal = ({ title = '', src = '', post = null, onClose = null, compact = false } = {}) => {
  const frameName = `velk-modal-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  const modal = document.createElement('div');
  modal.className = compact ? 'modal modal--compact' : 'modal';
  modal.innerHTML = `
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__body">
      <header class="modal__header">
        <h2 class="modal__title">${escapeHtml(title)}</h2>
        <button type="button" class="modal__close" data-modal-close aria-label="${escapeHtml(t('js_close', 'Close'))}">×</button>
      </header>
      <div class="modal__content">
        <iframe class="modal__iframe" name="${frameName}"${post ? '' : ` src="${escapeHtml(src)}"`}></iframe>
      </div>
    </div>
  `;
  // Uma janela aberta por cima de outra (a biblioteca sobre a de um bloco):
  // a de baixo some enquanto esta estiver aberta — duas sombras escureciam a
  // página o dobro, e a de baixo aparecia nas bordas quando a de cima era
  // menor. `visibility`, não `display`: o iframe de baixo guarda o tamanho e
  // a rolagem, e volta como estava. A sombra desta já entra cheia, sem o fade.
  const covered = [...document.querySelectorAll('body > .modal')].filter((m) => !m.classList.contains('modal--covered'));
  covered.forEach((m) => m.classList.add('modal--covered'));
  if (covered.length) modal.classList.add('modal--stacked');
  document.body.appendChild(modal);
  document.body.style.overflow = 'hidden';
  const iframe = modal.querySelector('iframe');
  const stopFitting = compact ? fitModalToContent(modal, iframe) : () => {};

  // O que está atrás da janela fica inerte enquanto ela estiver aberta: não
  // recebe foco nem Tab, e o que for digitado não chega lá. Sem isso, uma
  // tecla digitada com a janela aberta cai na página de trás — no editor de
  // texto, com um cartão de bloco selecionado, ela o troca pelo caractere.
  // (O desfazer do navegador não passa pelo foco: Cmd/Ctrl+Z com o foco fora
  // da janela ainda pode desfazer o último trecho digitado no editor.)
  const opener = document.activeElement;
  const background = [...document.body.children].filter((el) => el !== modal && !el.inert);
  background.forEach((el) => { el.inert = true; });
  // Quem tinha o foco o perde já: o navegador só o tiraria de um elemento
  // inerte no quadro seguinte. O close() o devolve.
  if (opener && opener !== document.body) opener.blur?.();

  if (post) {
    // Fora de qualquer formulário da página: no <body>, só pelo tempo do envio.
    const form = document.createElement('form');
    form.method = 'post';
    form.action = post.action;
    form.target = frameName;
    form.hidden = true;
    post.fields.forEach(([name, value]) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
    form.remove();
  }

  let closed = false;
  const close = () => {
    // Guard against double-close (overlay click + ESC fire in sequence
    // would otherwise invoke onClose twice).
    if (closed) return;
    closed = true;
    document.removeEventListener('keydown', onKey);
    stopFitting();
    background.forEach((el) => { el.inert = false; });
    covered.forEach((m) => m.classList.remove('modal--covered'));
    modal.remove();
    // A biblioteca aberta por cima da janela de um bloco fecha com a janela
    // ainda aberta embaixo: a página de trás continua sem rolar.
    if (!document.querySelector('body > .modal')) document.body.style.overflow = '';
    if (opener?.isConnected) opener.focus?.({ preventScroll: true });
    if (typeof onClose === 'function') onClose();
  };
  modal.querySelectorAll('[data-modal-close]').forEach((el) => el.addEventListener('click', close));
  // Sai no close(), feche a janela como fechar: fechada pelo × ou por uma
  // escolha, ela deixava o ouvinte na página para sempre.
  const onKey = (e) => {
    if (e.key !== 'Escape' || closed) return;
    // Com duas janelas abertas (a biblioteca sobre a de um bloco), Esc fecha
    // só a de cima: as duas escutam a mesma tecla.
    if ([...document.querySelectorAll('body > .modal')].pop() !== modal) return;
    close();
  };
  document.addEventListener('keydown', onKey);

  return { close, iframe };
};
// As janelas de dentro (a de um bloco é um iframe) abrem a biblioteca por aqui
// — ver openMediaPicker.
window.__velkOpenModal = openModal;

// A biblioteca de mídia abre por cima de tudo, na página do painel — também
// quando quem pede é a janela de um bloco: aberta ali, ela ficava presa ao
// iframe da janela, uma janela dentro da outra. Quem abre é a janela de mais
// acima que roda este admin.js; a escolha volta pelo `__velkPickerCallback`
// dela, a que a página da biblioteca responde (é o `window.parent` do iframe
// da biblioteca), e o callback é o do campo que pediu.
const pickerHost = () => {
  let host = window;
  try {
    while (host.parent !== host && typeof host.parent.__velkOpenModal === 'function') host = host.parent;
  } catch {
    // Uma janela de outra origem por cima: fica a última do painel.
  }
  return host;
};

// `multiple`: escolher várias (a galeria). Clicar seleciona ou tira; o botão
// "Selecionar" do rodapé entrega a lista inteira ao `onPick` de uma vez, e
// fechar sem ele não muda nada. A seleção mora aqui, na janela de cima
// (`__velkPickerSelection`), e não na página da biblioteca: trocar de página
// ali recarrega o iframe, e as marcas iriam junto.
const openMediaPicker = ({ title, src, onPick, onClose = null, multiple = false, selected = [], current = null }) => {
  const host = pickerHost();
  host.__velkPickerCallback = onPick;
  // `current`: a mídia que o campo de uma tem agora — a biblioteca a mostra
  // marcada, como a seleção da galeria.
  const currentId = !multiple && parseInt(current, 10) > 0 ? String(parseInt(current, 10)) : null;
  if (currentId) host.__velkPickerCurrent = currentId;
  else delete host.__velkPickerCurrent;
  // `selected`: o que abre já selecionado (o que está na galeria). Cópias
  // criadas lá, como as que a biblioteca acrescenta. `startCount` deixa o
  // "Selecionar" valer também para esvaziar uma galeria que tinha imagens.
  const selection = multiple ? new host.Array() : null;
  if (selection) {
    selected.forEach((data) => selection.push(host.JSON.parse(JSON.stringify(data))));
    selection.startCount = selection.length;
    host.__velkPickerSelection = selection;
  } else {
    delete host.__velkPickerSelection;
  }
  return host.__velkOpenModal({
    title,
    src,
    // Fechar, escolhendo ou não, desliga o callback e a seleção: não podem
    // vazar para uma biblioteca aberta depois por outro campo.
    onClose: () => {
      if (host.__velkPickerCallback === onPick) delete host.__velkPickerCallback;
      if (selection && host.__velkPickerSelection === selection) delete host.__velkPickerSelection;
      if (currentId && host.__velkPickerCurrent === currentId) delete host.__velkPickerCurrent;
      if (typeof onClose === 'function') onClose();
    },
  });
};

const escapeHtml = (str) =>
  String(str ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]);

// atob() returns a "binary string" where each character represents one
// raw byte. Assigning that directly to innerHTML makes the browser
// interpret bytes as Latin-1 — which turns UTF-8 multi-byte sequences
// (anything with accents, em-dashes, the icons we used to embed, etc.)
// into mojibake. This decodes the bytes through TextDecoder so the
// resulting string is real UTF-8 text safe to inject.
const decodeBase64Utf8 = (b64) => {
  const binStr = atob(b64);
  const bytes = new Uint8Array(binStr.length);
  for (let i = 0; i < binStr.length; i++) {
    bytes[i] = binStr.charCodeAt(i);
  }
  return new TextDecoder('utf-8').decode(bytes);
};

// ── Image field ────────────────────────────────────────────────────────────
const initImageField = (root) => {
  const input = root.querySelector('[data-image-field-input]');
  const preview = root.querySelector('[data-image-field-preview]');
  const pickBtn = root.querySelector('[data-image-field-pick]');
  const clearBtn = root.querySelector('[data-image-field-clear]');
  if (!input || !preview || !pickBtn) return;

  const renderPreview = (data) => {
    if (!data) {
      preview.innerHTML = '';
      input.value = '';
      pickBtn.textContent = t('js_image_select', 'Select image');
      if (clearBtn) clearBtn.hidden = true;
      return;
    }
    const isImage = data.is_image !== false && data.thumb_url;
    const visual = isImage
      ? `<img class="image-field__current-img" src="${escapeHtml(data.thumb_url)}" alt="">`
      : `<div class="image-field__current-doc">${escapeHtml((data.filename || '').split('.').pop().toUpperCase())}</div>`;
    const dims = data.width && data.height ? ` · ${data.width}×${data.height}` : '';
    preview.innerHTML = `
      <div class="image-field__current">
        ${visual}
        <div class="image-field__meta">
          <strong>${escapeHtml(data.original_name || data.filename || '')}</strong>
          <span class="muted">${escapeHtml(data.human_size || '')} · ${escapeHtml(data.mime || '')}${dims}</span>
        </div>
      </div>
    `;
    input.value = String(data.id);
    pickBtn.textContent = t('js_image_change', 'Change image');
    if (clearBtn) clearBtn.hidden = false;
  };

  pickBtn.addEventListener('click', () => {
    const modal = openMediaPicker({
      title: t('js_media_library_title', 'Media library'),
      src: `${ADMIN_BASE}/media?picker=1&accept=image`,
      current: input.value,
      onPick: (data) => {
        if (!data || data.is_image === false) return; // campo é só-imagem
        renderPreview(data);
        modal.close();
      },
    });
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', () => renderPreview(null));
  }
};

// ── File field ─────────────────────────────────────────────────────────────
// Espelho do image field pra QUALQUER mídia da biblioteca. O data-accept do
// markup (CSV de categorias, vindo do site.json) filtra o picker via
// ?accept=; single-pick — escolher fecha o modal.
const initFileField = (root) => {
  const input = root.querySelector('[data-file-field-input]');
  const preview = root.querySelector('[data-file-field-preview]');
  const pickBtn = root.querySelector('[data-file-field-pick]');
  const clearBtn = root.querySelector('[data-file-field-clear]');
  if (!input || !preview || !pickBtn) return;

  const accept = (root.dataset.accept || '').trim();
  const acceptParam = accept ? `&accept=${accept}` : '';

  const renderPreview = (data) => {
    if (!data) {
      preview.innerHTML = '';
      input.value = '';
      pickBtn.textContent = t('js_file_select', 'Choose file');
      if (clearBtn) clearBtn.hidden = true;
      return;
    }
    const category = data.category || (data.is_image ? 'image' : 'document');
    let visual;
    if (category === 'image' && (data.thumb_url || data.url)) {
      visual = `<img class="file-field__thumb" src="${escapeHtml(data.thumb_url || data.url)}" alt="">`;
    } else if (category === 'video') {
      visual = `<video class="file-field__thumb" src="${escapeHtml(data.url)}" preload="metadata" muted playsinline></video>`;
    } else {
      visual = `<div class="file-field__doc">${escapeHtml((data.filename || '').split('.').pop().toUpperCase())}</div>`;
    }
    preview.innerHTML = `
      <div class="file-field__current">
        ${visual}
        <div class="file-field__meta">
          <strong>${escapeHtml(data.original_name || data.filename || '')}</strong>
          <span class="muted">${escapeHtml(data.human_size || '')} · ${escapeHtml(data.mime || '')}</span>
        </div>
      </div>
    `;
    input.value = String(data.id);
    pickBtn.textContent = t('js_file_change', 'Change file');
    if (clearBtn) clearBtn.hidden = false;
  };

  pickBtn.addEventListener('click', () => {
    const modal = openMediaPicker({
      title: t('js_media_library_title', 'Media library'),
      src: `${ADMIN_BASE}/media?picker=1${acceptParam}`,
      current: input.value,
      onPick: (data) => {
        if (!data) return;
        renderPreview(data);
        modal.close();
      },
    });
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', () => renderPreview(null));
  }
};

// ── Item field ─────────────────────────────────────────────────────────────
// Combobox com busca para selecionar UM item de um item type. As opções
// (somente publicados) já vêm renderizadas no DOM pelo servidor; aqui só
// filtramos por texto, abrimos/fechamos o painel abaixo do campo, e setamos
// o id no input hidden ao escolher. Sem AJAX, sem dependências. Não montamos
// HTML a partir de dados (usamos .value/.dataset), então não há risco de XSS.
const initItemField = (root) => {
  const input = root.querySelector('[data-item-field-input]');
  const search = root.querySelector('[data-item-field-search]');
  const clearBtn = root.querySelector('[data-item-field-clear]');
  const menu = root.querySelector('[data-item-field-menu]');
  if (!input || !search || !menu) return;

  const options = () => Array.from(menu.querySelectorAll('.item-field__option'));
  const visibleOptions = () => options().filter((o) => !o.hidden);
  const clearActive = () => options().forEach((o) => o.classList.remove('is-active'));
  const openMenu = () => { menu.hidden = false; };
  const closeMenu = () => { menu.hidden = true; clearActive(); };

  const filter = () => {
    const q = search.value.trim().toLowerCase();
    options().forEach((o) => {
      const title = (o.dataset.title || '').toLowerCase();
      o.hidden = q !== '' && !title.includes(q);
    });
    clearActive();
  };

  const select = (opt) => {
    input.value = opt.dataset.id || '';
    search.value = opt.dataset.title || '';
    if (clearBtn) clearBtn.hidden = false;
    options().forEach((o) => { o.hidden = false; });
    closeMenu();
  };

  const clear = () => {
    input.value = '';
    search.value = '';
    if (clearBtn) clearBtn.hidden = true;
    options().forEach((o) => { o.hidden = false; });
    closeMenu();
  };

  search.addEventListener('focus', openMenu);
  search.addEventListener('input', () => { filter(); openMenu(); });

  menu.addEventListener('click', (e) => {
    const opt = e.target.closest('.item-field__option');
    if (opt) select(opt);
  });

  if (clearBtn) clearBtn.addEventListener('click', clear);

  search.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeMenu(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (menu.hidden) openMenu();
      const vis = visibleOptions();
      if (vis.length === 0) return;
      let idx = vis.findIndex((o) => o.classList.contains('is-active'));
      clearActive();
      idx = e.key === 'ArrowDown'
        ? (idx + 1) % vis.length
        : (idx <= 0 ? vis.length - 1 : idx - 1);
      vis[idx].classList.add('is-active');
      vis[idx].scrollIntoView({ block: 'nearest' });
      return;
    }
    if (e.key === 'Enter') {
      const active = visibleOptions().find((o) => o.classList.contains('is-active'));
      if (active) { e.preventDefault(); select(active); }
    }
  });
};

// ── Gallery field ──────────────────────────────────────────────────────────
// Gallery = ordered list of media IDs. The DOM is the source of truth:
// each thumbnail wraps a hidden input with the media ID, and the browser
// submits them in DOM order. Drag-and-drop just reorders the DOM, so we
// never need to track positions explicitly. Empty galleries submit
// nothing at all (PHP's collectGallery returns [] for that case).
const initGalleryField = (root) => {
  const itemsEl = root.querySelector('[data-gallery-items]');
  const emptyEl = root.querySelector('[data-gallery-empty]');
  const addBtn = root.querySelector('[data-gallery-add]');
  const fieldName = root.dataset.galleryName || 'gallery';
  if (!itemsEl || !addBtn) return;

  const updateEmptyState = () => {
    const hasItems = itemsEl.querySelector('[data-gallery-item]') !== null;
    if (emptyEl) emptyEl.hidden = hasItems;
  };

  // ASCII-safe inline SVG matches the server-rendered close icon
  // (FieldRenderer::renderGalleryItem) so newly-picked items look
  // identical to existing ones.
  const closeIconSvg = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 3l6 6M9 3l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

  const buildItem = (data) => {
    const isImage = data.is_image !== false && data.thumb_url;
    const visual = isImage
      ? `<img class="gallery-field__thumb" src="${escapeHtml(data.thumb_url)}" alt="${escapeHtml(data.alt || '')}">`
      : `<div class="gallery-field__doc">${escapeHtml((data.filename || '').split('.').pop().toUpperCase())}</div>`;
    const div = document.createElement('div');
    div.className = 'gallery-field__item';
    div.dataset.galleryItem = '';
    div.draggable = true;
    div.innerHTML = `
      <input type="hidden" name="${escapeHtml(fieldName)}[]" value="${parseInt(data.id, 10)}">
      ${visual}
      <button type="button" class="gallery-field__remove" data-gallery-remove aria-label="${escapeHtml(t('js_remove', 'Remove'))}">${closeIconSvg}</button>
    `;
    return div;
  };

  // Drag-and-drop reordering. We track the active dragged element in a
  // closure (not on the DOM) so it survives even if the user drags fast
  // enough to fire events out of order. Decision rule: dropping on the
  // left half of a sibling inserts BEFORE; right half inserts AFTER.
  // This gives the expected behavior for both LTR and grid layouts.
  let draggedItem = null;

  const bindItem = (item) => {
    item.addEventListener('dragstart', (e) => {
      draggedItem = item;
      item.classList.add('is-dragging');
      // Required for Firefox to actually start the drag; the data
      // payload is unused since we use a closure ref instead.
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
      }
    });

    item.addEventListener('dragend', () => {
      item.classList.remove('is-dragging');
      draggedItem = null;
      itemsEl.querySelectorAll('.is-drag-over').forEach((el) => el.classList.remove('is-drag-over'));
    });

    item.addEventListener('dragover', (e) => {
      if (!draggedItem || draggedItem === item) return;
      e.preventDefault();
      item.classList.add('is-drag-over');
    });

    item.addEventListener('dragleave', () => {
      item.classList.remove('is-drag-over');
    });

    item.addEventListener('drop', (e) => {
      e.preventDefault();
      item.classList.remove('is-drag-over');
      if (!draggedItem || draggedItem === item) return;
      const rect = item.getBoundingClientRect();
      const midpoint = rect.left + rect.width / 2;
      if (e.clientX < midpoint) {
        item.parentNode.insertBefore(draggedItem, item);
      } else {
        item.parentNode.insertBefore(draggedItem, item.nextSibling);
      }
    });

    const removeBtn = item.querySelector('[data-gallery-remove]');
    removeBtn?.addEventListener('click', () => {
      item.remove();
      updateEmptyState();
    });
  };

  // Bind any items the server already rendered.
  itemsEl.querySelectorAll('[data-gallery-item]').forEach(bindItem);
  updateEmptyState();

  addBtn.addEventListener('click', () => {
    // A seleção da biblioteca é a galeria: ela abre com o que já está aqui
    // selecionado, na ordem daqui. Clicar seleciona ou tira; "Selecionar"
    // deixa a galeria igual à seleção, e o × fecha sem mudar nada.
    const current = [];
    itemsEl.querySelectorAll('[data-gallery-item] input[type="hidden"]').forEach((input) => {
      const id = parseInt(input.value, 10);
      if (id > 0 && !current.some((data) => data.id === id)) current.push({ id });
    });
    const modal = openMediaPicker({
      title: t('js_gallery_add_title', 'Add images to gallery'),
      src: `${ADMIN_BASE}/media?picker=1&accept=image`,
      multiple: true,
      selected: current,
      onPick: (picked) => {
        // As miniaturas que seguem selecionadas ficam onde estão, repetidas
        // inclusive (a marca não mostra ordem, então a biblioteca não
        // reordena nada); as tiradas saem; as novas entram no fim, na ordem
        // dos cliques.
        const list = (Array.isArray(picked) ? picked : [picked]).filter(Boolean);
        const chosen = new Set(list.map((data) => String(parseInt(data.id, 10))));
        const present = new Set();
        itemsEl.querySelectorAll('[data-gallery-item]').forEach((el) => {
          const id = el.querySelector('input[type="hidden"]')?.value;
          if (!id) return;
          if (chosen.has(id)) present.add(id);
          else el.remove();
        });
        list.forEach((data) => {
          const id = String(parseInt(data.id, 10));
          if (present.has(id) || data.is_image === false) return; // galeria é só-imagem
          const item = buildItem(data);
          itemsEl.appendChild(item);
          bindItem(item);
          present.add(id);
        });
        updateEmptyState();
        modal.close();
      },
    });
  });
};

// ── Flexible content field ─────────────────────────────────────────────────
// Like a repeater, but each row picks its own layout (a named group of
// fields, declared upfront in site.json). Storage shape is a list of
// dicts each starting with `_layout`, so the theme can switch on it.
//
// Row names use bracket notation with explicit indices —
// `blocks[0][title]`, `blocks[1][image]` — so reordering rows in the
// DOM is NOT enough; we have to rewrite the outer index every time
// rows are added, removed, or dragged. The reindex() function does
// exactly that (same pattern as the repeater).
const initFlexibleField = (root) => {
  // `:scope >` for the same reason as initRepeater — defends against the
  // hijack-lookup bug if a flexible field is ever nested inside another
  // flexible (or inside a repeater that contains another flexible). Today
  // it's uncommon, but the cost of the fix is zero and the savings on
  // hours of "why doesn't the Add button work" debugging are real.
  const rowsEl = root.querySelector(':scope > [data-flexible-rows]');
  const emptyEl = root.querySelector(':scope > [data-flexible-empty]');
  // Add buttons live inside a `.flexible-field__add` wrapper that is a
  // direct child of root (FieldRenderer renders them there, alongside a
  // label). Select through it with `>` so a nested flexible's buttons
  // (which sit deeper, inside the rows) are never captured here.
  const addBtns = root.querySelectorAll(':scope > .flexible-field__add > [data-flexible-add], :scope > [data-flexible-add]');
  const fieldName = root.dataset.flexibleName || '';
  // Only the rows container is essential. Missing add buttons must NOT abort
  // the whole field — existing rows stay editable/draggable even if the add
  // selector ever drifts again. The addBtns.forEach below no-ops when empty.
  if (!rowsEl) return;

  const updateEmptyState = () => {
    const hasRows = rowsEl.querySelector(':scope > [data-flexible-row]') !== null;
    if (emptyEl) emptyEl.hidden = hasRows;
  };

  // Reindex: walk rows in DOM order and rewrite the OUTER bracket index
  // on every input's name. Inner brackets (nested subfields) are left
  // untouched. This is what makes drag-and-drop reordering actually
  // reorder the submitted data.
  const reindex = () => {
    const prefix = fieldName;
    const re = new RegExp('^(' + prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')\\[(\\d+|__INDEX__)\\]');
    rowsEl.querySelectorAll(':scope > [data-flexible-row]').forEach((row, idx) => {
      row.querySelectorAll('[name]').forEach((el) => {
        el.name = el.name.replace(re, `$1[${idx}]`);
      });
    });
  };

  // Drag-and-drop reorder via the shared helper (see makeRowsSortable).
  const sortable = makeRowsSortable(rowsEl, { onReorder: reindex });

  const bindRow = (row) => {
    sortable.bindRow(row);

    // Collapse / expand
    const toggleBtn = row.querySelector(':scope > [data-flexible-row-header] [data-flexible-toggle]');
    toggleBtn?.addEventListener('click', () => {
      row.classList.toggle('is-collapsed');
    });

    // The WHOLE header toggles (anywhere the user clicks), except the
    // buttons (remove has its own confirm; the chevron has its own
    // handler above) and the drag handle — clicking that is grabbing,
    // not toggling.
    const header = row.querySelector(':scope > [data-flexible-row-header]');
    header?.addEventListener('click', (e) => {
      if (e.target.closest('button')) return;
      if (e.target.closest('.flexible-field__row-handle')) return;
      row.classList.toggle('is-collapsed');
    });

    // Remove with confirm — flexible rows often hold a lot of input,
    // so accidental clicks would be costly.
    const removeBtn = row.querySelector(':scope > [data-flexible-row-header] [data-flexible-remove]');
    removeBtn?.addEventListener('click', () => {
      if (!confirm(t('js_confirm_remove_block', 'Remove this block? Filled-in data will be lost.'))) return;
      row.remove();
      reindex();
      updateEmptyState();
    });
  };

  // Bind already-rendered rows from the server.
  rowsEl.querySelectorAll(':scope > [data-flexible-row]').forEach(bindRow);
  updateEmptyState();

  addBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      const layoutKey = btn.dataset.flexibleAdd;
      const tplEl = root.querySelector(`:scope > [data-flexible-template="${layoutKey}"]`);
      if (!tplEl) return;

      // Stamp a fresh row from the layout template. The placeholder
      // index doesn't matter — we reindex() right after to keep
      // everything sequential. decodeBase64Utf8 (vs raw atob) ensures
      // multi-byte UTF-8 in labels like "Parágrafo" or "Citação"
      // renders correctly instead of mojibake-ing as Latin-1.
      const idx = rowsEl.querySelectorAll(':scope > [data-flexible-row]').length;
      const templateHtml = decodeBase64Utf8(tplEl.textContent.trim());
      const html = templateHtml.replace(/__INDEX__/g, idx.toString());
      const wrapper = document.createElement('div');
      wrapper.innerHTML = html;
      const newRow = wrapper.firstElementChild;
      if (!newRow) return;

      rowsEl.appendChild(newRow);
      bindRow(newRow);

      // Initialize any nested widgets inside the new row. Flexible
      // can contain anything a regular form can — including other
      // flexible/repeater fields if the user wires it up.
      newRow.querySelectorAll('[data-richtext]').forEach(initRichText);
      newRow.querySelectorAll('[data-repeater]').forEach(initRepeater);
      newRow.querySelectorAll('[data-image-field]').forEach(initImageField);
      newRow.querySelectorAll('[data-file-field]').forEach(initFileField);
      newRow.querySelectorAll('[data-item-field]').forEach(initItemField);
      newRow.querySelectorAll('[data-gallery-field]').forEach(initGalleryField);
      newRow.querySelectorAll('[data-flexible-field]').forEach(initFlexibleField);

      reindex();
      updateEmptyState();

      // Scroll the new row into view so the user can immediately see
      // the freshly added fields — without this, on long pages the
      // row appends below the fold and feels unresponsive.
      newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });
};

// ── Media uploader (drop + queue) ──────────────────────────────────────────
const initMediaUploader = (root) => {
  const drop = root.querySelector('[data-media-uploader-drop]');
  const fileInput = root.querySelector('[data-media-uploader-input]');
  const trigger = root.querySelector('[data-media-uploader-trigger]');
  const queue = root.querySelector('[data-media-uploader-queue]');
  const url = root.dataset.uploadUrl;
  const csrf = root.dataset.csrf;
  if (!drop || !fileInput || !queue || !url) return;

  // Limites reais (server = min(upload_max_filesize, post_max_size); 0 = sem teto)
  const serverLimit = parseInt(root.dataset.serverLimit || '0', 10) || Infinity;
  let categoryLimits = {};
  try { categoryLimits = JSON.parse(root.dataset.categoryLimits || '{}'); } catch (e) { /* sem limites */ }
  const acceptCats = (root.dataset.accept || '').split(',').filter(Boolean);

  // Mesmo mapeamento de Media::categoryOf (PHP) — manter em sincronia.
  const categoryOf = (mime) => {
    if (!mime) return null;
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('video/')) return 'video';
    if (mime.startsWith('audio/') || mime === 'application/ogg') return 'audio';
    if (mime === 'application/zip') return 'archive';
    return 'document';
  };
  const humanMB = (bytes) => `${Math.round(bytes / 1048576)} MB`;

  trigger?.addEventListener('click', () => fileInput.click());
  drop.addEventListener('click', (e) => {
    if (e.target === drop) fileInput.click();
  });

  ['dragenter', 'dragover'].forEach((evt) => {
    drop.addEventListener(evt, (e) => {
      e.preventDefault();
      drop.classList.add('is-dragover');
    });
  });
  ['dragleave', 'drop'].forEach((evt) => {
    drop.addEventListener(evt, (e) => {
      e.preventDefault();
      drop.classList.remove('is-dragover');
    });
  });
  drop.addEventListener('drop', (e) => {
    if (e.dataTransfer?.files?.length) handleFiles(e.dataTransfer.files);
  });

  fileInput.addEventListener('change', () => {
    if (fileInput.files?.length) handleFiles(fileInput.files);
    fileInput.value = '';
  });

  const handleFiles = async (files) => {
    for (const file of files) {
      const li = document.createElement('li');
      li.className = 'media-uploader__queue-item';
      li.innerHTML = `<span>${escapeHtml(file.name)}</span><span data-status>${escapeHtml(t('js_uploading', 'Uploading…'))}</span>`;
      queue.appendChild(li);

      try {
        // Barra local ANTES do POST: arquivo maior que o teto (categoria da
        // app ou servidor) falharia do outro lado com o corpo descartado.
        const cat = categoryOf(file.type);
        const limit = Math.min((cat ? categoryLimits[cat] : undefined) ?? Infinity, serverLimit);
        if (Number.isFinite(limit) && file.size > limit) {
          throw new Error(
            t('js_upload_exceeds_limit', '{size} exceeds the limit of {limit}')
              .replace('{size}', humanMB(file.size))
              .replace('{limit}', humanMB(limit))
          );
        }

        const fd = new FormData();
        fd.append('file', file);
        fd.append('_csrf', csrf);
        const res = await fetch(url, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        let data = null;
        try { data = await res.json(); } catch (e) { /* resposta não-JSON */ }
        if (!res.ok || !data) throw new Error(
          (data && data.error) || t('js_upload_error_http', 'Upload error (HTTP {status})').replace('{status}', res.status)
        );
        li.classList.add('is-success');
        li.querySelector('[data-status]').textContent = t('js_uploaded', 'Uploaded');
        const tile = prependToGrid(data);
        // Auto-select no picker — só quando o arquivo bate o filtro accept
        // (fora do filtro ele ainda sobe pra biblioteca, só não seleciona).
        // Escolhendo várias (a galeria), ele só entra marcado: quem entrega é
        // o "Selecionar".
        const dataCat = data.category || categoryOf(data.mime);
        const fits = acceptCats.length === 0 || acceptCats.includes(dataCat);
        if (fits && pickerSelection()) {
          // Nunca entrega um item sozinho aqui: a galeria leria a lista de
          // um como a seleção inteira e tiraria o resto.
          if (tile) togglePick(tile);
        } else if (fits && window.parent && typeof window.parent.__velkPickerCallback === 'function') {
          window.parent.__velkPickerCallback(data);
        }
        setTimeout(() => li.remove(), 2500);
      } catch (err) {
        li.classList.add('is-error');
        li.querySelector('[data-status]').textContent = err.message || t('js_upload_failed', 'Upload failed');
      }
    }
  };
};

const prependToGrid = (data) => {
  const grid = document.querySelector('[data-media-grid]');
  if (!grid) return;
  document.querySelector('[data-media-empty]')?.remove();

  const li = document.createElement('li');
  li.className = 'media-grid__item';
  li.dataset.mediaId = String(data.id);
  li.dataset.mediaUrl = data.url;
  li.dataset.mediaThumb = data.thumb_url || '';
  li.dataset.mediaName = data.original_name || data.filename;
  li.dataset.mediaMime = data.mime;
  li.dataset.mediaSize = data.human_size;
  li.dataset.mediaDimensions = data.width && data.height ? `${data.width}×${data.height}` : '';
  li.dataset.mediaAlt = data.alt || '';
  li.dataset.mediaIsImage = data.is_image ? '1' : '0';
  li.dataset.mediaCategory = data.category || '';
  li.dataset.mediaCreatedAt = data.created_at || '';

  const ext = (data.filename || '').split('.').pop().toUpperCase();
  const tile = data.category === 'video'
    ? `<video class="media-grid__video" src="${escapeHtml(data.url)}" preload="metadata" muted playsinline></video>`
    : (data.is_image && data.thumb_url
      ? `<img src="${escapeHtml(data.thumb_url)}" alt="" loading="lazy">`
      : `<div class="media-grid__doc"><span>${escapeHtml(ext)}</span></div>`);

  li.innerHTML = `
    <button type="button" class="media-grid__tile" data-media-open>${tile}</button>
    <span class="media-grid__name" title="${escapeHtml(data.original_name)}">${escapeHtml(data.original_name)}</span>
  `;
  grid.prepend(li);
  bindGridItem(li);
  return li;
};

// ── Media grid (open detail panel or picker select) ─────────────────────────
const bindGridItem = (item) => {
  const btn = item.querySelector('[data-media-open]');
  if (!btn) return;
  btn.addEventListener('click', () => {
    const grid = item.closest('[data-media-grid]');
    const isPicker = grid?.dataset.picker === '1';
    if (isPicker && pickerSelection()) {
      // Escolhendo várias: o clique seleciona ou tira; o "Selecionar" entrega.
      togglePick(item);
    } else if (isPicker) {
      // Escolhendo uma: o clique já é a escolha.
      if (window.parent && typeof window.parent.__velkPickerCallback === 'function') {
        window.parent.__velkPickerCallback(mediaItemData(item));
      }
    } else {
      openMediaPanel(item);
    }
  });
};

// O que o seletor entrega de uma mídia da grade.
const mediaItemData = (item) => ({
  id: parseInt(item.dataset.mediaId, 10),
  url: item.dataset.mediaUrl,
  thumb_url: item.dataset.mediaThumb,
  filename: item.dataset.mediaName,
  original_name: item.dataset.mediaName,
  mime: item.dataset.mediaMime,
  human_size: item.dataset.mediaSize,
  width: parseInt((item.dataset.mediaDimensions || '').split('×')[0], 10) || null,
  height: parseInt((item.dataset.mediaDimensions || '').split('×')[1], 10) || null,
  alt: item.dataset.mediaAlt,
  is_image: item.dataset.mediaIsImage === '1',
  category: item.dataset.mediaCategory || '',
});

// ── Seleção de várias no seletor (galeria) ───────────────────────────────
// A lista das marcadas mora na janela que abriu a biblioteca
// (`__velkPickerSelection`, ver openMediaPicker): sobrevive à troca de página
// e à busca, que refazem a grade. Sem ela, o seletor é de uma mídia só.
const pickerSelection = () => {
  try {
    const selection = window.parent !== window ? window.parent.__velkPickerSelection : null;
    return Array.isArray(selection) ? selection : null;
  } catch {
    return null;
  }
};

// A mídia que o campo de uma tem agora (`__velkPickerCurrent`, ver
// openMediaPicker), ou null.
const pickerCurrent = () => {
  try {
    const current = window.parent !== window ? window.parent.__velkPickerCurrent : null;
    return typeof current === 'string' && current !== '' ? current : null;
  } catch {
    return null;
  }
};

// Marca os tiles selecionados — a seleção da galeria, ou a mídia atual do
// campo de uma — e, escolhendo várias, atualiza o rodapé.
const refreshPickMarks = () => {
  const selection = pickerSelection();
  const current = selection ? null : pickerCurrent();
  if (!selection && current === null) return;
  const ids = selection ? new Set(selection.map((data) => String(data.id))) : new Set([current]);
  document.querySelectorAll('[data-media-grid] .media-grid__item').forEach((li) => {
    li.classList.toggle('is-picked', ids.has(li.dataset.mediaId));
  });
  if (!selection) return;
  const footer = document.querySelector('[data-picker-footer]');
  if (!footer) return;
  const count = selection.length;
  footer.querySelector('[data-picker-count]').textContent = count === 0
    ? t('js_picker_none', 'No items selected')
    : (count === 1 ? t('js_picker_one', '1 item selected') : t('js_picker_many', '{n} items selected').replace('{n}', count));
  // Sem nada selecionado, só vale confirmar quando havia algo antes (é a
  // galeria sendo esvaziada).
  footer.querySelector('[data-picker-confirm]').disabled = count === 0 && !(selection.startCount > 0);
};

const togglePick = (item) => {
  const selection = pickerSelection();
  if (!selection) return;
  const at = selection.findIndex((data) => String(data.id) === item.dataset.mediaId);
  if (at >= 0) {
    selection.splice(at, 1);
  } else {
    // Uma cópia criada lá: guardar o objeto desta página manteria o
    // documento dela vivo depois de a página trocar.
    selection.push(window.parent.JSON.parse(JSON.stringify(mediaItemData(item))));
  }
  refreshPickMarks();
};

// O rodapé do seletor de várias: quantas estão marcadas e o "Selecionar",
// que entrega a lista. Sem ele, só o × — que agora cancela — fecharia a
// janela, e não parecia guardar o que foi escolhido.
const initPicker = (grid) => {
  if (grid.dataset.picker !== '1') return;
  // Esc com o foco aqui dentro (depois de um clique na grade) fica neste
  // documento, e quem fecha a janela escuta o de cima: a tecla vai para lá.
  // Na busca com texto, o Esc do navegador limpa o campo — fica com ele.
  if (window.parent !== window) {
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape' || e.defaultPrevented) return;
      if (e.target instanceof HTMLInputElement && e.target.type === 'search' && e.target.value !== '') return;
      try {
        window.parent.document.dispatchEvent(new window.parent.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      } catch {
        // Outra origem por cima: não há o que avisar.
      }
    });
  }
  if (!pickerSelection() || document.querySelector('[data-picker-footer]')) {
    refreshPickMarks(); // o seletor de uma só marca a atual
    return;
  }
  const footer = document.createElement('div');
  footer.className = 'picker-footer';
  footer.dataset.pickerFooter = '';
  footer.innerHTML = `
    <span class="picker-footer__count" data-picker-count></span>
    <button type="button" class="button" data-picker-confirm disabled>${escapeHtml(t('js_picker_select', 'Select'))}</button>
  `;
  footer.querySelector('[data-picker-confirm]').addEventListener('click', () => {
    const selection = pickerSelection();
    if (selection && typeof window.parent.__velkPickerCallback === 'function') {
      window.parent.__velkPickerCallback(selection.slice());
    }
  });
  document.querySelector('.embed__main')?.appendChild(footer);
  refreshPickMarks();
};

// ── Copiar o link público (topo de item, página e termo) ──────────────────
// Copia o endereço completo — o href da tela é relativo — e troca o ícone por
// um ✓ por um instante. Sem a API da área de transferência (painel em http
// fora do localhost), o jeito antigo; se nem ele der, mostra o link para
// copiar à mão.
const COPY_LINK_FEEDBACK = 1500;
const initCopyLink = (btn) => {
  const label = btn.getAttribute('aria-label') || '';
  let timer = null;
  btn.addEventListener('click', async () => {
    const url = new URL(btn.dataset.copyLink || '', window.location.href).href;
    let copied = false;
    try {
      await navigator.clipboard.writeText(url);
      copied = true;
    } catch {
      const area = document.createElement('textarea');
      area.value = url;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      try { copied = document.execCommand('copy'); } catch { copied = false; }
      area.remove();
    }
    if (!copied) {
      window.prompt(label, url);
      return;
    }
    const done = btn.dataset.copiedLabel || label;
    btn.classList.add('is-copied');
    btn.title = done;
    btn.setAttribute('aria-label', done);
    clearTimeout(timer);
    timer = setTimeout(() => {
      btn.classList.remove('is-copied');
      btn.title = label;
      btn.setAttribute('aria-label', label);
    }, COPY_LINK_FEEDBACK);
  });
};

// ── Media search (library page and picker) ─────────────────────────────────
// Refaz a consulta enquanto se digita e troca só [data-media-results] — a
// grade, a paginação e as mensagens — sem recarregar. A busca é do servidor
// (`q`), para alcançar a biblioteca inteira e não só a página aberta. O
// formulário é um GET de verdade: Enter navega como sempre se o JS falhar.
const MEDIA_SEARCH_DELAY = 250;
const initMediaSearch = (form) => {
  const input = form.querySelector('[data-media-search]');
  const results = document.querySelector('[data-media-results]');
  if (!input || !results) return;
  let timer = null;
  let inflight = null;
  let lastQuery = input.value.trim();

  const urlFor = (query) => {
    const params = new URLSearchParams(new FormData(form));
    params.delete('page');
    if (query === '') params.delete('q');
    else params.set('q', query);
    const qs = params.toString();
    return qs === '' ? form.action : `${form.action}?${qs}`;
  };

  const run = async () => {
    const query = input.value.trim();
    if (query === lastQuery) return;
    lastQuery = query;
    const url = urlFor(query);

    inflight?.abort();
    const controller = new AbortController();
    inflight = controller;
    results.setAttribute('aria-busy', 'true');
    try {
      const res = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'text/html' } });
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      const fresh = doc.querySelector('[data-media-results]');
      // Sem o bloco de resultados (sessão vencida → tela de login, erro do
      // servidor), navega para a URL: a pessoa vê o que o servidor respondeu.
      if (!res.ok || !fresh) {
        window.location.href = url;
        return;
      }
      // As marcas do seletor de várias vêm da seleção (refreshPickMarks).
      results.innerHTML = fresh.innerHTML;
      results.querySelectorAll('[data-media-grid] .media-grid__item').forEach(bindGridItem);
      refreshPickMarks();
      const count = document.querySelector('[data-media-count]');
      const freshCount = doc.querySelector('[data-media-count]');
      if (count && freshCount) count.textContent = freshCount.textContent;
      // A URL acompanha a busca: recarregar ou voltar mantém o filtro.
      history.replaceState(history.state, '', url);
    } catch (e) {
      if (e.name !== 'AbortError') window.location.href = url;
    } finally {
      if (inflight === controller) {
        inflight = null;
        results.removeAttribute('aria-busy');
      }
    }
  };

  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(run, MEDIA_SEARCH_DELAY);
  });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    clearTimeout(timer);
    run();
  });
};

// ── Media detail panel ─────────────────────────────────────────────────────
const openMediaPanel = (item) => {
  const panel = document.querySelector('[data-media-panel]');
  if (!panel) return;
  const id = item.dataset.mediaId;

  panel.querySelector('[data-media-panel-name]').textContent = item.dataset.mediaName;
  panel.querySelector('[data-media-panel-mime]').textContent = item.dataset.mediaMime;
  panel.querySelector('[data-media-panel-size]').textContent = item.dataset.mediaSize;
  panel.querySelector('[data-media-panel-dimensions]').textContent = item.dataset.mediaDimensions || '—';
  panel.querySelector('[data-media-panel-created]').textContent = item.dataset.mediaCreatedAt;
  panel.querySelector('[data-media-panel-url]').value = item.dataset.mediaUrl;
  panel.querySelector('[data-media-panel-alt]').value = item.dataset.mediaAlt;

  // i18n: populate per-language alt inputs. Parse the JSON stored
  // on the grid item (server-side `data-media-alt-translations`),
  // then assign each value to its matching input. Inputs for
  // languages with no translation stay empty.
  let altTranslations = {};
  try {
    altTranslations = JSON.parse(item.dataset.mediaAltTranslations || '{}');
  } catch (e) { /* malformed JSON: keep empty */ }
  panel.querySelectorAll('[data-media-panel-alt-translation]').forEach((input) => {
    const lang = input.dataset.mediaPanelAltTranslation;
    input.value = altTranslations[lang] || '';
  });

  const previewEl = panel.querySelector('[data-media-panel-preview]');
  const category = item.dataset.mediaCategory || (item.dataset.mediaIsImage === '1' ? 'image' : '');
  if (category === 'image') {
    previewEl.innerHTML = `<img src="${escapeHtml(item.dataset.mediaUrl)}" alt="">`;
  } else if (category === 'video') {
    previewEl.innerHTML = `<video src="${escapeHtml(item.dataset.mediaUrl)}" controls playsinline></video>`;
  } else if (category === 'audio') {
    previewEl.innerHTML = `<audio src="${escapeHtml(item.dataset.mediaUrl)}" controls></audio>`;
  } else {
    const ext = (item.dataset.mediaName || '').split('.').pop().toUpperCase();
    previewEl.innerHTML = `<div class="media-grid__doc" style="height:200px;width:200px"><span>${escapeHtml(ext)}</span></div>`;
  }

  // Wire alt-text save
  const altForm = panel.querySelector('[data-media-panel-alt-form]');
  altForm.onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(altForm);
    fd.append('alt', panel.querySelector('[data-media-panel-alt]').value);
    const res = await fetch(`${ADMIN_BASE}/media/${id}`, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const status = panel.querySelector('[data-media-panel-alt-status]');
    if (res.ok) {
      status.textContent = t('js_saved', 'Saved.');
      // Sync the grid item's dataset so re-opening the panel shows
      // the latest values without a page reload.
      item.dataset.mediaAlt = panel.querySelector('[data-media-panel-alt]').value;
      const updatedTranslations = {};
      panel.querySelectorAll('[data-media-panel-alt-translation]').forEach((input) => {
        const v = input.value.trim();
        if (v !== '') updatedTranslations[input.dataset.mediaPanelAltTranslation] = v;
      });
      item.dataset.mediaAltTranslations = JSON.stringify(updatedTranslations);
      setTimeout(() => { status.textContent = ''; }, 2000);
    } else {
      status.textContent = t('js_save_error', 'Error saving.');
    }
  };

  // Wire delete
  const deleteForm = panel.querySelector('[data-media-panel-delete-form]');
  deleteForm.onsubmit = async (e) => {
    e.preventDefault();
    const message = deleteForm.dataset.confirm || t('js_confirm_delete', 'Delete?');
    if (!window.confirm(message)) return;
    const fd = new FormData(deleteForm);
    const res = await fetch(`${ADMIN_BASE}/media/${id}/delete`, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (res.ok) {
      item.remove();
      closeMediaPanel();
    } else {
      window.alert(t('js_delete_error', 'Error deleting.'));
    }
  };

  panel.hidden = false;
  document.body.style.overflow = 'hidden';
};

const closeMediaPanel = () => {
  const panel = document.querySelector('[data-media-panel]');
  if (!panel) return;
  panel.hidden = true;
  document.body.style.overflow = '';
};

document.querySelectorAll('[data-media-panel-close]').forEach((el) =>
  el.addEventListener('click', closeMediaPanel)
);

/*
 * Mobile menu toggle.
 *
 * On viewports ≤980px the sidebar is off-canvas (CSS hides it via
 * transform). The hamburger button toggles a `data-app-menu="open"`
 * attribute on the .app shell — CSS uses that to slide the sidebar in
 * and reveal the backdrop. Backdrop, ESC, and clicking any nav link
 * all close the menu, matching what users expect from this UI pattern.
 *
 * No-op on desktop because the toggle button is `display: none` there.
 */
const initMobileMenu = () => {
  const app = document.querySelector('[data-app]');
  const toggle = document.querySelector('[data-menu-toggle]');
  const backdrop = document.querySelector('[data-menu-backdrop]');
  if (!app || !toggle) return;

  const setOpen = (open) => {
    if (open) app.dataset.appMenu = 'open';
    else delete app.dataset.appMenu;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? t('js_menu_close', 'Close menu') : t('js_menu_open', 'Open menu'));
  };

  toggle.addEventListener('click', () => {
    setOpen(app.dataset.appMenu !== 'open');
  });

  if (backdrop) {
    backdrop.addEventListener('click', () => setOpen(false));
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && app.dataset.appMenu === 'open') setOpen(false);
  });

  // Auto-close after navigating from a sidebar link — saves the user
  // a tap once they've picked their destination.
  app.querySelectorAll('.app__sidebar a').forEach((link) => {
    link.addEventListener('click', () => setOpen(false));
  });
};

// Native form validation vs collapsed containers: a `required` field
// inside a display:none ancestor (collapsed repeater/flexible row,
// inactive tab panel) can't be focused by the browser, which silently
// BLOCKS the submit with no visible feedback. Chrome auto-opens
// <details>, but knows nothing about our class-based collapsing.
// This capture-phase listener expands every collapsed ancestor of the
// invalid field before the browser tries to focus it, walking node by
// node so interleaved containers (a tab inside a collapsed row inside
// another tab) all open along the way.
document.addEventListener('invalid', (e) => {
  const el = e.target;
  if (!(el instanceof Element)) return;
  for (let n = el.parentElement; n; n = n.parentElement) {
    if (n.tagName === 'DETAILS' && !n.open && n.matches('[data-field-group]')) {
      n.open = true;
    }
    if (n.classList && n.classList.contains('is-collapsed') && n.matches('[data-repeater-row], [data-flexible-row]')) {
      n.classList.remove('is-collapsed');
    }
    if (n.matches && n.matches('[data-field-tab-panel]') && !n.classList.contains('is-active')) {
      const panelsWrap = n.parentElement;
      const root = panelsWrap?.parentElement;
      const nav = root?.querySelector(':scope > .field-tabs__nav');
      if (nav && panelsWrap) {
        const panels = Array.from(panelsWrap.children).filter((p) => p.matches('[data-field-tab-panel]'));
        const tabs = Array.from(nav.children).filter((t) => t.matches('[data-field-tab]'));
        const idx = panels.indexOf(n);
        if (idx !== -1) {
          tabs.forEach((t, i) => {
            t.classList.toggle('is-active', i === idx);
            t.setAttribute('aria-selected', i === idx ? 'true' : 'false');
          });
          panels.forEach((p, i) => p.classList.toggle('is-active', i === idx));
        }
      }
    }
  }
}, true);

// Field tabs (organizational containers from FieldRenderer) — a single
// DELEGATED listener instead of per-instance init. Two reasons:
//   1. Tabs cloned into fresh repeater/flexible rows work immediately,
//      with no re-init bookkeeping in those widgets' add handlers.
//   2. No state to leak: pairing is ordinal (button N ↔ panel N within
//      the SAME .field-tabs), computed at click time.
// Children are matched via direct-child filtering so a tabs container
// nested inside another tabs' panel never toggles its parent (same
// lesson as the nested-repeater `:scope >` fix).
document.addEventListener('click', (e) => {
  const tab = e.target.closest('[data-field-tab]');
  if (!tab) return;
  const root = tab.closest('[data-field-tabs]');
  if (!root) return;
  const nav = root.querySelector(':scope > .field-tabs__nav');
  const panelsWrap = root.querySelector(':scope > .field-tabs__panels');
  if (!nav || !panelsWrap || tab.parentElement !== nav) return;

  const tabs = Array.from(nav.children).filter((el) => el.matches('[data-field-tab]'));
  const panels = Array.from(panelsWrap.children).filter((el) => el.matches('[data-field-tab-panel]'));
  const idx = tabs.indexOf(tab);
  if (idx === -1) return;

  tabs.forEach((t, i) => {
    t.classList.toggle('is-active', i === idx);
    t.setAttribute('aria-selected', i === idx ? 'true' : 'false');
  });
  panels.forEach((p, i) => p.classList.toggle('is-active', i === idx));
});

// Cada parte do painel liga isolada. O arquivo é um módulo, e uma exceção
// no topo dele interromperia todo o resto: um erro numa parte — um campo com
// um formato inesperado numa tela — levaria junto todas as seguintes (as
// abas de idioma, a tabela de traduções, o menu do celular). O boot() manda
// o erro para o console e segue; o bootEach() isola também cada elemento,
// para um repetidor quebrado não impedir os outros da mesma tela.
const boot = (init, label = init.name) => {
  try {
    init();
  } catch (e) {
    console.error(`[Velk admin] ${label} failed`, e);
  }
};
const bootEach = (selector, init) => {
  document.querySelectorAll(selector).forEach((el) => boot(() => init(el), init.name));
};

// A resposta da janela de um bloco (blocks/saved, dentro do iframe): entrega
// `{type, data}` à página de fora, que guarda o bloco no editor.
const initBlockResult = () => {
  const el = document.getElementById('velk-block-result');
  if (!el) return;
  let result = null;
  try { result = JSON.parse(el.textContent); } catch { return; }
  if (window.parent !== window && typeof window.parent.__velkBlockCallback === 'function') {
    window.parent.__velkBlockCallback(result);
  }
};

bootEach('[data-richtext]', initRichText);
bootEach('[data-repeater]', initRepeater);
bootEach('[data-image-field]', initImageField);
bootEach('[data-file-field]', initFileField);
bootEach('[data-item-field]', initItemField);
// Um único listener delegado fecha qualquer item-field aberto ao clicar fora
// dele — evita um listener por instância (que vazaria em linhas removidas).
document.addEventListener('click', (e) => {
  document.querySelectorAll('[data-item-field]').forEach((field) => {
    if (field.contains(e.target)) return;
    const menu = field.querySelector('[data-item-field-menu]');
    if (!menu || menu.hidden) return;
    menu.hidden = true;
    menu.querySelectorAll('.item-field__option.is-active').forEach((o) => o.classList.remove('is-active'));
  });
});
bootEach('[data-gallery-field]', initGalleryField);
bootEach('[data-flexible-field]', initFlexibleField);
bootEach('[data-show-if]', initConditionalField);
bootEach('[data-reveal-target]', initRevealToggle);
bootEach('[data-sortable-table]', initSortableTable);
bootEach('[data-media-uploader]', initMediaUploader);
bootEach('[data-media-search-form]', initMediaSearch);
bootEach('[data-copy-link]', initCopyLink);
bootEach('[data-media-grid] .media-grid__item', bindGridItem);
bootEach('[data-media-grid]', initPicker);
boot(initBlockResult);
boot(initSlugify);
boot(initConfirms);
boot(initAutosubmit);
boot(initLangTabs);
boot(initTranslationsTable);
boot(initMobileMenu);
boot(initBulkActions);
