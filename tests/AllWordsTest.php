<?php

require('TernaryNode.php');
require('TernaryTree.php');


$words = file('/usr/share/dict/words', FILE_IGNORE_NEW_LINES);

$tree = new TernaryTree(true);


$test_dict = array();
foreach($words as $word) {
    $test_dict[$word] = rand();
}


$tree->build($test_dict);


?>
