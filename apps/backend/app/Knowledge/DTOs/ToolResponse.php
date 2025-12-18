<?php

namespace App\Knowledge\DTOs;

class ToolResponse
{
    public function __construct(
        public readonly string $tool,
        public readonly string $text,
        public readonly array $data = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'text' => $this->text,
            'data' => $this->data,
        ];
    }
}
