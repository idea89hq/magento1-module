<?php
/**
 * Unit-test bootstrap for the Magento 1 module. No OpenMage install is
 * needed: the code under test is loaded directly and `Mage` is a small
 * stand-in whose models each test sets.
 */

define('IDEA89_M1_DIR', dirname(__DIR__) . '/app/code/community/Idea89/Assistant/');

if (!class_exists('Mage')) {
    class Mage
    {
        /** @var array<string, object> */
        public static array $models = [];
        /** @var array<string, object> */
        public static array $singletons = [];

        public static function getModel(string $name, $args = [])
        {
            return self::$models[$name] ?? null;
        }

        public static function getSingleton(string $name, $args = [])
        {
            return self::$singletons[$name] ?? null;
        }

        public static function log($message, $level = null, $file = '')
        {
        }

        public static function reset(): void
        {
            self::$models = [];
            self::$singletons = [];
        }
    }
}

/** A Varien_Object-like stand-in: getX()/setX() over a data array. */
class Idea89_Test_DataObject
{
    public array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function __call(string $method, array $args)
    {
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method, 3)));
        if (strncmp($method, 'get', 3) === 0) {
            return $this->data[$key] ?? null;
        }
        if (strncmp($method, 'set', 3) === 0) {
            $this->data[$key] = $args[0] ?? null;
            return $this;
        }
        return null;
    }

    public function load($id, $field = null)
    {
        return $this;
    }

    public function getData($key = '')
    {
        return $key === '' ? $this->data : ($this->data[$key] ?? null);
    }
}

require_once IDEA89_M1_DIR . 'Model/Sync/ProductSerializer.php';

if (!class_exists('Mage_Catalog_Model_Product_Status')) {
    class Mage_Catalog_Model_Product_Status
    {
        const STATUS_ENABLED = 1;
        const STATUS_DISABLED = 2;
    }
}
if (!class_exists('Mage_Catalog_Model_Product_Visibility')) {
    class Mage_Catalog_Model_Product_Visibility
    {
        const VISIBILITY_NOT_VISIBLE = 1;
        const VISIBILITY_IN_CATALOG = 2;
        const VISIBILITY_IN_SEARCH = 3;
        const VISIBILITY_BOTH = 4;
    }
}
if (!class_exists('Zend_Log')) {
    class Zend_Log
    {
        const ERR = 3;
        const WARN = 4;
        const INFO = 6;
    }
}
require_once IDEA89_M1_DIR . 'Model/Sync/CatalogSyncer.php';
