<?php

declare(strict_types=1);

namespace Velk;

final class TemplateContext
{
    public string $type;
    public string $key;
    public array $data;
    public mixed $record = null;
    public array $records = [];

    public function __construct(string $type, string $key, array $data)
    {
        $this->type = $type;
        $this->key = $key;
        $this->data = $data;
    }
}
