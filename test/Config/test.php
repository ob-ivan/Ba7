<?php

$test = 'bob';

$here = dirname(__FILE__);
include dirname (dirname ($here)) . '/source/framework/class/Config.class.php';

try
{
    $config = new Ba7\Framework\Config ($here . '/' . $test . '.config');
}
catch (Exception $e)
{
    print '<pre>' . $e->getMessage() . '</pre>';
    die;
}

print '<pre>' . $config . '</pre>';

