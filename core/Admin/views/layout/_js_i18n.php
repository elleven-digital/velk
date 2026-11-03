<?php
/**
 * Os textos que o admin.js mostra, no idioma de quem está logado — num bloco
 * de dados (json_script), e não num script que atribui uma variável: o CSP
 * do painel não aceita JavaScript embutido. O admin.js lê pelo id.
 *
 * Um parcial só, incluído pelo layout do painel (main.php) e pelo do iframe
 * do seletor de mídia (embed.php), que roda o mesmo fluxo de upload.
 */
echo json_script('velk-admin-i18n', [
        'js_link_prompt'          => __('fields.js_link_prompt'),
        'js_media_library_title'  => __('fields.js_media_library_title'),
        'js_close'                => __('fields.js_close'),
        'js_confirm_generic'      => __('fields.js_confirm_generic'),
        'js_image_select'         => __('fields.js_image_select'),
        'js_image_change'         => __('fields.js_image_change'),
        'js_file_select'          => __('fields.js_file_select'),
        'js_file_change'          => __('fields.js_file_change'),
        'js_remove'               => __('fields.js_remove'),
        'js_gallery_add_title'    => __('fields.js_gallery_add_title'),
        'js_picker_select'        => __('fields.js_picker_select'),
        'js_picker_none'          => __('fields.js_picker_none'),
        'js_picker_one'           => __('fields.js_picker_one'),
        'js_picker_many'          => __('fields.js_picker_many'),
        'js_confirm_remove_block' => __('fields.js_confirm_remove_block'),
        'js_uploading'            => __('fields.js_uploading'),
        'js_upload_exceeds_limit' => __('fields.js_upload_exceeds_limit'),
        'js_upload_error_http'    => __('fields.js_upload_error_http'),
        'js_uploaded'             => __('fields.js_uploaded'),
        'js_upload_failed'        => __('fields.js_upload_failed'),
        'js_saved'                => __('fields.js_saved'),
        'js_save_error'           => __('fields.js_save_error'),
        'js_confirm_delete'       => __('fields.js_confirm_delete'),
        'js_delete_error'         => __('fields.js_delete_error'),
        'js_menu_close'           => __('fields.js_menu_close'),
        'js_menu_open'            => __('fields.js_menu_open'),
        // Blocos do tema no editor de texto (o cartão e a janela).
        'js_block_edit'           => __('fields.js_block_edit'),
        'js_block_unavailable'    => __('fields.js_block_unavailable'),
        'js_block_unreadable'     => __('fields.js_block_unreadable'),
        // Exclusão em massa (admin.js initBulkActions) — reaproveita o
        // catálogo items.* (mesmo texto usado no render inicial da barra
        // server-side), não fields.js_* — evita duplicar a mesma string
        // em dois lugares que poderiam divergir com o tempo.
        'js_bulk_selected_one'    => __('items.bulk_selected_one'),
        'js_bulk_selected_other'  => __('items.bulk_selected_other'),
]);
