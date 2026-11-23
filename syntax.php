<?php

use dokuwiki\Extension\SyntaxPlugin;

class syntax_plugin_takes extends SyntaxPlugin
{
    public function getType()
    {
        return 'substition';
    }

    public function getSort()
    {
        return 155;
    }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~TAKES~~', $mode, 'plugin_takes');
    }

    public function handle($match, $state, $pos, \dokuwiki\Parsing\Handler $handler)
    {
        return [];
    }

    public function render($mode, Doku_Renderer $renderer, $data)
    {
        return true;
    }
}
