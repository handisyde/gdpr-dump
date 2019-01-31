<?php

namespace machbarmacher\GdprDump\ColumnTransformer;


use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use machbarmacher\GdprDump\ColumnTransformer\Plugins\ClearColumnTransformer;
use machbarmacher\GdprDump\ColumnTransformer\Plugins\FakerColumnTransformer;

abstract class ColumnTransformer
{

    const COLUMN_TRANSFORM_REQUEST = "columntransform.request";

    const DEFAULT_LOCALE = 'en_US';

    private $tableName;

    private $columnName;

    protected static $dispatcher;

    protected static $locale = self::DEFAULT_LOCALE;

    public static function setLocale($locale)
    {
        self::$locale = $locale;
    }

    public static function setUp()
    {
        if (!isset(self::$dispatcher)) {
            self::$dispatcher = new EventDispatcher();
            self::$dispatcher->addListener(self::COLUMN_TRANSFORM_REQUEST,
              new FakerColumnTransformer(self::$locale));
            self::$dispatcher->addListener(self::COLUMN_TRANSFORM_REQUEST,
              new ClearColumnTransformer());
        }

    }

    public static function replaceValue($tableName, $columnName, $expression)
    {
        self::setUp();
        $event = new ColumnTransformEvent($tableName, $columnName, $expression);
        self::$dispatcher->dispatch(self::COLUMN_TRANSFORM_REQUEST, $event);
        if ($event->isReplacementSet()) {
            return $event->getReplacementValue();
        }

        return false;
    }

    public static function getAllFormatters()
    {
        self::setUp();
        $result = [];
        foreach(self::$dispatcher->getListeners(self::COLUMN_TRANSFORM_REQUEST) as $listener) {
            $result = array_merge($result, $listener->getSupportedFormatters());
        }
        return $result;
    }

    public function __invoke(ColumnTransformEvent $event)
    {
        if (in_array(($event->getExpression())['formatter'],
          $this->getSupportedFormatters())) {
            $event->setReplacementValue($this->getValue($event->getExpression()));
        }
    }

    abstract public function getValue($expression);

    abstract protected function getSupportedFormatters();
}
