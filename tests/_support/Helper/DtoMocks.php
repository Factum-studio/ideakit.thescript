<?php

namespace tests\_support\Helper;

/**
 * @example
 * if (!class_exists('core\application\dto\ItemDto')) {
 *  class ItemDto
 *  {
 *      public $data;
 *      public function __construct($data) { $this->data = $data; }
 *  }
 * }
 */

if (!class_exists('core\application\dto\ItemDto')) {
    class ItemDto
    {
        public $data;
        public function __construct($data) { $this->data = $data; }
    }
}

if (!class_exists('core\application\dto\CollectionDto')) {
    class CollectionDto
    {
        public $items;
        public $total;
        public function __construct($items, $total = null)
        {
            $this->items = $items;
            $this->total = $total;
        }
    }
}

if (!class_exists('core\application\dto\ErrorDto')) {
    class ErrorDto
    {
        public $message;
        public $code;
        public $details;
        public function __construct($message, $code, $details)
        {
            $this->message = $message;
            $this->code = $code;
            $this->details = $details;
        }
    }
}

if (!class_exists('core\application\dto\SuccessDto')) {
    class SuccessDto
    {
        public $data;
        public $message;
        public function __construct($data, $message)
        {
            $this->data = $data;
            $this->message = $message;
        }
    }
}
