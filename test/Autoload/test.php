<?php

$here = dirname(__FILE__);
include dirname (dirname ($here)) . '/source/framework/class/Autoload.class.php';

Ba7\Framework\Autoload::init();

Ba7\Framework\Autoload::register ('[\\]', $here . '/[1].php');

Alice::run();
Bob\Cat::run();

Ba7\Framework\Autoload::register ('[Child|Parent|?]Element', $here . '/element/[1.]element.php');

Element::run();
ChildElement::run();

