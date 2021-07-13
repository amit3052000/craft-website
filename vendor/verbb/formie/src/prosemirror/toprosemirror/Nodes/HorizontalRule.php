<?php

namespace verbb\formie\prosemirror\toprosemirror\Nodes;

class HorizontalRule extends Node
{
    public function matching()
    {
        return $this->DOMNode->nodeName === 'hr';
    }

    public function data()
    {
        return [
            'type' => 'horizontal_rule',
        ];
    }
}
