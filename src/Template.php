<?php

namespace Shura\StringTemplate;

use InvalidArgumentException;
use Closure;

class Template
{
    protected $provider;
    protected $start;
    protected $stop;
    protected $regex;
    protected $unescapeRegex;

    public function __construct($provider, $start = '{{', $stop = '}}')
    {
        if (!(is_callable($provider) || $provider instanceof Closure || is_array($provider))) {
            throw new InvalidArgumentException('Argument 1 passed to '.
                __CLASS__.'::'.__METHOD__.'() must be of type callable, '.
                'Closure or array, '.self::get_type($provider).' given.');
        }

        if (!is_string($start) || empty($start)) {
            throw new InvalidArgumentException('Argument 2 passed to '.
                __CLASS__.'::'.__METHOD__.'() must be of type string and not '.
                'empty, '.self::get_type($start).' given.');
        }

        if (!is_string($stop) || empty($stop)) {
            throw new InvalidArgumentException('Argument 2 passed to '.
                __CLASS__.'::'.__METHOD__.'() must be of type string and not '.
                'empty, '.self::get_type($stop).' given.');
        }

        $this->provider = is_array($provider) ? $this->wrapArrayProvider($provider) : $provider;
        $this->start = $start;
        $this->stop = $stop;
    }

    protected function wrapArrayProvider($array)
    {
        return function ($name) use (&$array) {
            return isset($array[$name]) ? $array[$name] : null;
        };
    }

    protected function escapedStringMatcher($str)
    {
        return '\\\\'.
            implode('\\\\?', array_map(function ($str) {
                return preg_quote($str, '/');
            },
            preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY)));
    }

    protected function getRegex()
    {
        if (!isset($this->regex)) {
            $escapedStart = $this->escapedStringMatcher($this->start);
            $escapedStop = $this->escapedStringMatcher($this->stop);
            $start = preg_quote($this->start, '/');
            $stop = preg_quote($this->stop, '/');
            $this->regex = '/'.$escapedStart.'|'.$escapedStop.
                '|\\\\.|'.$start.'(?:\\'.$stop.'|.)+?'.$stop.'/';
        }

        return $this->regex;
    }

    protected function isVariable($str)
    {
        return (strlen($this->start) + strlen($this->stop)) < strlen($str) &&
            substr($str, 0, strlen($this->start)) == $this->start &&
            substr($str, -strlen($this->stop)) == $this->stop;
    }

    protected function unescapeVariable($str)
    {
        $startlen = strlen($this->start);

        return substr($str, $startlen, strlen($str) - ($startlen + strlen($this->stop)));
    }

    protected function handleMatches($matches, $provider)
    {
        $match = $matches[0];

        if (substr($match, 0, 2) == '\\'.substr($this->start, 0, 1)) {
            $match = $this->start;
        } elseif (substr($match, 0, 2) == '\\'.substr($this->stop, 0, 1)) {
            $match = $this->stop;
        } elseif ($this->isVariable($match)) {
            $var = $this->unescapeVariable($match);
            $tmp = $provider($var);
            if (!is_null($tmp)) {
                $match = $tmp;
            }
        }

        return $match;
    }

    public function replace($string, $mutator = null)
    {
        if (!(is_callable($mutator) || $mutator instanceof Closure || is_null($mutator))) {
            throw new InvalidArgumentException('Argument 2 passed to '.
                __CLASS__.'::'.__METHOD__.'() must be of type callable, '.
                'Closure or NULL, '.self::get_type($mutator).' given.');
        }

        $fn = $this->provider;
        if (!is_null($mutator)) {
            $fn = function ($name) use ($fn, $mutator) {
                return $mutator($fn($name));
            };
        }

        $handler = function ($matches) use ($fn) {
            return $this->handleMatches($matches, $fn);
        };

        return preg_replace_callback($this->getRegex(), $handler, $string);
    }

    public static function get_type($var)
    {
        $type = gettype($var);

        return $type == 'object' ? get_class($var) : $type;
    }
}
