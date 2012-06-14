<?php

$here = dirname(__FILE__);
include dirname (dirname ($here)) . '/source/framework/bootstrap.php';

Ba7\Framework\Autoload::init();

Ba7\Framework\Autoload::register ('[\\]', $here . '/[1].php');

Alice::run();
Bob\Cat::run();

Ba7\Framework\Autoload::register ('[Child|Parent|?][Element]', $here . '/[2|case:lower]/[1.][2|case:lower].php');

Element::run();
ChildElement::run();

