<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of TernaryTree
 *
 * @author trent
 */
class TernaryTree {

    protected $_root;
    private $_numKeys;  // private since we cannot guarantee this will be valid when loaded from cache.
    private $_numNodes;
    protected $_do_cache;
    protected $_read_only;

    const ROOT_NODE_KEY = 'TernaryNode|id|0';
    const POST_ORDER = 1;

    public function __construct($caching = false, $read_only = false) {
	$this->_numKeys = 0;
	$this->_numNodes = 0;
	$this->_do_cache = $caching;
	$this->_read_only = $read_only;
    }

    /**
     * Builds a ternary tree from a dictionary of key/values
     * Keys should be strings, if not they are skipped.
     *
     * @param Array(string => Object) $key_values
     */
    public function build(&$key_values) {
	if (!isset($key_values) || !is_array($key_values)) {
	    return;
	}

	// check for an existing cached structure if we are caching, if it exists, delete it.
	if ($this->_do_cache) {
	    if (IcMemcache::getInstance()->get(self::ROOT_NODE_KEY)) {
		self::deleteCachedTree(self::ROOT_NODE_KEY);
	    }
	}

	// first sort the dictionary based on the key values
	ksort($key_values);
	$key_index = array_keys($key_values);

	// then begin the recursive build process
	$this->buildRecursive(
		$key_index,
		$key_values,
		0,
		count($key_index)
	);

	// save the tree to cache if we're caching
	if($this->_do_cache) {
	    $this->saveToCache();
	}
    }

    /**
     * Recursive build function to construct a reasonably balanced tree.
     * Assumes $key_index is an indexed array of sorted keys.
     *
     * @param Array(int => string)    $key_index
     * @param Array(string => Object) $key_values
     */
    private function buildRecursive(&$key_index, &$key_values, $start, $n) {

	if ($n < 1) {
	    return;
	}

	$mid = $n >> 1;  //much faster integer division by 2, PHP defaults division to float
	// recursively add the mid key, then
	$key = $key_index[$start + $mid];
	$this->insert($key, $key_values[$key]);

	$this->buildRecursive(
		$key_index,
		$key_values,
		$start,
		$mid
	);

	$this->buildRecursive(
		$key_index,
		$key_values,
		$start + $mid + 1,
		$n - $mid - 1
	);
    }

    /**
     * Inserts a given key/value pair.
     * Non-string keys are ignored.
     *
     * @param string $key
     * @param Object $value
     * @return true if successful, false if unsuccessful
     */
    public function insert(&$key, &$value) {

	if (!isset($key) || !is_string($key) || $this->_read_only) {
	    return false;
	}

	$key_chars = str_split($key);

	$this->insertIterative($key_chars, $value);

	$this->_numKeys++;

	return true;
    }


    public function insertIterative(&$key_chars, &$value) {

	if($this->_read_only) {
	    return;
	}

	$node = &$this->_root;

	while(current($key_chars)) {

	    if(!$node || TernaryNode::isNullNode($node)) {
		$node = $this->_do_cache ? new TernaryNodeMemcached($this->_numNodes) : new TernaryNode();
		$node->keyChar = current($key_chars);
		$this->_numNodes++;
	    }

	    $comparison = strcmp(current($key_chars), $node->keyChar);
	    if ($comparison < 0) {
		$node = &$node->getLeftNode();
	    } else if ($comparison > 0) {
		$node = &$node->getRightNode();
	    } else {
		if (!next($key_chars)) {
		    $node->value = $value;
		}
		$node = &$node->getMiddleNode();
	    }
	}
    }


    /**
     * Here we actually perform the insertion, laying down the key characters
     * as we go and placing the value at the end.
     *
     * @param TernaryNode $node
     * @param array(chars) $key_chars
     * @param Object $value
     */
    private function insertRecursive(&$node, &$key_chars, &$value) {

	if ($this->_read_only)
	    return;

	if (!current($key_chars))
	    return;

	// node didn't exist, let's lay down a key
	if (!$node || TernaryNode::isNullNode($node)) {
	    if (current($key_chars)) {

		$node = $this->_do_cache ? new TernaryNodeMemcached($this->_numNodes) : new TernaryNode();
		$node->keyChar = current($key_chars);

		$this->_numNodes++;
	    }
	}

	// node exists, where do we go now?
	$comparison = strcmp(current($key_chars), $node->keyChar);
	if ($comparison < 0) {
	    $this->insertRecursive(
		    $node->getLeftNode(),
		    $key_chars,
		    $value
	    );
	} else if ($comparison > 0) {
	    $this->insertRecursive(
		    $node->getRightNode(),
		    $key_chars,
		    $value
	    );
	} else {
	    if (current($key_chars)) {
		// we're at the end of our key, leave a value here
		if (!next($key_chars)) {
		    $node->value = $value;
		}
		$this->insertRecursive(
			$node->getMiddleNode(),
			$key_chars,
			$value
		);
	    }
	}

	if ($this->_do_cache) {
	    $node->save();
	}
    }

    /**
     * Returns all key/value pairs beginning with $string
     *
     * @param string $string
     * @return Array(string => object)
     */
    public function prefixSearch($string) {

	$node = $this->_root;
	$chars = str_split($string);

	try {
	    while (!TernaryNode::isNullNode($node)) {

		$comparison = strcmp(current($chars), $node->keyChar);
		if ($comparison < 0) {
		    $node = &$node->getLeftNode();
		} else if ($comparison > 0) {
		    $node = &$node->getRightNode();
		} else {
		    if (!next($chars))
			return self::asArray($node, $string);
		    $node = &$node->getMiddleNode();
		}
	    }
	} catch (KeyNotFoundException $exc) {
	    throw new TreeCorruptException('Tree corrupt.', null, $exc);
	}

	return false;
    }

    public function traverseTree($order, $onvisit_callback) {
	self::traverseNodeRecursive($this->_root, $onvisit_callback, $order);
    }

    static function traverseNodeRecursive(&$node, $onvisit_callback, $order = self::POST_ORDER) {
	if (!$node)
	    return;

	if (TernaryNode::isNullNode($node))
	    return;

	// in case we want to add more traversal orders in the future.
	switch ($order) {
	    case self::POST_ORDER:
		if ($node->hasLeftNode()) {
		    self::traverseNodeRecursive($node->getLeftNode(), $onvisit_callback);
		}
		if ($node->hasMiddleNode()) {
		    self::traverseNodeRecursive($node->getMiddleNode(), $onvisit_callback);
		}
		if ($node->hasRightNode()) {
		    self::traverseNodeRecursive($node->getRightNode(), $onvisit_callback);
		}
		$onvisit_callback($node);
		break;
	}
    }

    protected function saveToCache() {
	$this->traverseTree(self::POST_ORDER, function($node) {
	    $node->save();
	});
    }

    protected function loadFromCache($cache_key) {
	$root = IcMemcache::getInstance()->get($cache_key);
	if ($root) {
	    $this->_root = $root;
	    return true;
	} else {
	    return false;
	}
    }

    protected function deleteFromCache() {
	$this->traverseTree(self::POST_ORDER, function($node) {
		    $node->delete();
		});
    }

    static function asArrayIter(&$node, $current_key) {
	$stack = array();
	$keystack = array();
	$output = array();

	if (is_null($node)) {
	    return $output;
	}

	while (true) {
	    if ($node->getLeftNode()) {
		$stack[] = &$node->getLeftNode();
	    }

	    if ($node->getMiddleNode()) {
		$stack[] = &$node->getMiddleNode();
	    }

	    if ($node->getRightNode()) {
		$stack[] = &$node->getRightNode();
	    }

	    if ($node->value) {
		$output[$current_key] = $node->value;
	    }

	    if (empty($stack))
		break;

	    $node = array_pop($stack);
	    $current_key .= $node->keyChar;
	}

	return $output;
    }

    static function asArray(&$node, $current_key) {

	$out = array();

	if (is_null($node)) {
	    return $out;
	}

	self::asArrayRecursive($node, $current_key, $out, true);

	return $out;
    }

    static function asArrayRecursive(&$node, $current_key, &$out, $first = false) {
	if (!$first) {
	    $current_key .= $node->keyChar;
	}

	if ($node->value) {
	    $out[$current_key] = $node->value;
	}

	if ($node->hasLeftNode()) {
	    self::asArrayRecursive(
			    $node->getLeftNode(),
			    substr($current_key, 0, -1),
			    $out
	    );
	}

	if ($node->hasMiddleNode()) {

	    self::asArrayRecursive(
			    $node->getMiddleNode(),
			    $current_key,
			    $out
	    );
	}

	if ($node->hasRightNode()) {
	    self::asArrayRecursive(
			    $node->getRightNode(),
			    substr($current_key, 0, -1),
			    $out
	    );
	}

	return;
    }

    static function getCachedTree($cache_key) {
	$tree = new TernaryTree(false, true);
	return $tree->loadFromCache(self::ROOT_NODE_KEY) ? $tree : false;
    }

    static function deleteCachedTree($cache_key) {
	$tree = new TernaryTree(false, true);
	$tree->loadFromCache(self::ROOT_NODE_KEY);
	$tree->deleteFromCache();
    }

}

class TreeCorruptException extends Exception {
    
}

?>
