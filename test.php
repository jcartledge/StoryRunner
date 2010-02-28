<?php

require 'storyrunner.php';
require 'browser.php';

$context = array('browser' => new SimpleBrowser);

$runner = new StoryRunner;
$runner->run($context);