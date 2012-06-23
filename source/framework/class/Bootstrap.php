<?php

namespace Ba7\Framework;

interface BootstrapInterface
{
    static public function init ($frameworkRoot);
}

class Bootstrap implements BootstrapInterface
{
    // public : BootstrapInterface //
    
    static public function init ($frameworkRoot)
    {
        require_once $frameworkRoot . '/class/Autoload.php';
        require_once $frameworkRoot . '/class/Filter.php';
        
        Autoload::init();
        Autoload::register ('Ba7\\Framework\\[]');
    }
}
