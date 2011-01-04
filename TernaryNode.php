<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of TernaryNode
 *
 * @author Trent Strong <trent at ichange.com>
 * @since Nov 12, 2010
 */
class TernaryNode {
    const NO_NODE = -1;
    protected $left;
    protected $middle;
    protected $right;
    public $keyChar;
    public $value;

    public function __construct() {
	$this->left = self::NO_NODE;
	$this->right = self::NO_NODE;
	$this->middle = self::NO_NODE;
    }

    public function &getLeftNode() {
	return $this->left;
    }

    public function &getMiddleNode() {
	return $this->middle;
    }

    public function &getRightNode() {
	return $this->right;
    }

    public function hasLeftNode() {
	return!self::isNullNode($this->left);
    }

    public function hasMiddleNode() {
	return!self::isNullNode($this->middle);
    }

    public function hasRightNode() {
	return!self::isNullNode($this->right);
    }

    public static function isNullNode($node) {
	return (is_int($node) && $node == self::NO_NODE);
    }

}

/**
 * Implements an extension of TernaryNode that lazy-loads its descendant nodes from a memcached backend.
 */
class TernaryNodeMemcached extends TernaryNode {
    const CACHE_KEY_FORMAT = 'TernaryNode|id|%d';


    public $node_id;

    public function __construct($node_id) {
	parent::__construct();
	$this->node_id = $node_id;
    }

    /**
     *
     * @return TernaryNode
     */
    public function &getLeftNode() {
	if (!isset($this->left)) {
	    $this->left = self::NO_NODE;
	}

	if (is_int($this->left)) {
	    if ($this->left != self::NO_NODE) {
		$cache_key = self::getCacheKey($this->left);

		$left = IcMemcache::getInstance()->get($cache_key);
		if (!$left) {
		    throw new KeyNotFoundException("Key not found in memcached backend.  Tree should be rebuilt");
		}
		$this->left = $left;
	    }
	}

	return $this->left;
    }

    public function &getMiddleNode() {
	if (!isset($this->middle)) {
	    $this->middle = self::NO_NODE;
	}

	if (is_int($this->middle)) {
	    if ($this->middle != self::NO_NODE) {
		$cache_key = self::getCacheKey($this->middle);

		$middle = IcMemcache::getInstance()->get($cache_key);
		if (!$middle) {
		    throw new KeyNotFoundException("Key not found in memcached backend.  Tree should be rebuilt");
		}
		$this->middle = $middle;
	    }
	}
	return $this->middle;
    }

    public function &getRightNode() {
	if (!isset($this->right)) {
	    $this->right = self::NO_NODE;
	}

	if (is_int($this->right)) {
	    if ($this->right != self::NO_NODE) {
		$cache_key = self::getCacheKey($this->right);

		$right = IcMemcache::getInstance()->get($cache_key);
		if (!$right) {
		    throw new KeyNotFoundException("Key not found in memcached backend.  Tree should be rebuilt");
		}
		$this->right = $right;
	    }
	}
	return $this->right;
    }

    static function getCacheKey($node_id) {
	return sprintf(self::CACHE_KEY_FORMAT, $node_id);
    }

    public function save() {

// store temporary references to descendants
	$left = $this->left;
	$middle = $this->middle;
	$right = $this->right;


// replace descendants with their IDs to enable lazy-loading, then save
	if ($this->hasLeftNode())
	    $this->left = $this->left->node_id;

	if ($this->hasMiddleNode())
	    $this->middle = $this->middle->node_id;

	if ($this->hasRightNode())
	    $this->right = $this->right->node_id;

	$cache_key = self::getCacheKey($this->node_id);
	IcMemcache::getInstance()->set($cache_key, $this);


// replace references
	$this->left = $left;
	$this->middle = $middle;
	$this->right = $right;
    }

    public function delete() {
	$cache_key = self::getCacheKey($this->node_id);
	IcMemcache::getInstance()->delete($cache_key, 0);  // the extra 0 argument is a workaround for PECL memcached bug http://pecl.php.net/bugs/bug.php?id=17566
    }

}

class KeyNotFoundException extends Exception {

}

?>
