<?php

namespace Ba7\Framework
{
    // Filter //

    interface FilterInterface
    {
        public function __construct (array $arguments = array());
        
        public function apply ($value);
        
        public function append (FilterInterface $filter);
    }

    class FilterException extends \Exception
    {
        const ARGUMENTS_NOT_VALID       = __LINE__;
        const ARGUMENT_VALUES_UNKNOWN   = __LINE__;
        const NOT_ENOUGH_ARGUMENTS      = __LINE__;
        const UNREACHABLE_CODE          = __LINE__;
    }

    class Filter implements FilterInterface
    {
        // var //
    
        protected $arguments;
        protected $next;
    
        // public : FilterInterface //
        
        final public function __construct (array $arguments = array())
        {
            if (! ($this->validate ($arguments)))
            {
                throw new FilterException (
                    'Argument list (' . implode (':', $arguments) . ') is not valid for filter ' . get_class ($this) . '; ' .
                    'implementation SHOULD provide more diagnostic info',
                    FilterException::ARGUMENTS_NOT_VALID
                );
            }
            
            $this->arguments = $arguments;
        }
        
        final public function apply ($value)
        {
            $value = $this->implement ($value);
            if ($this->next)
            {
                $value = $this->next->apply ($value);
            }
            return $value;
        }
        
        final public function append (FilterInterface $filter)
        {
            if (! $this->next)
            {
                $this->next = $filter;
            }
            else
            {
                $this->next->append ($filter);
            }
        }
        
        // protected : to be redefined in descendant classes //
        
        protected function validate ($arguments)
        {
            return true;
        }
        
        protected function implement ($value)
        {
            return $value;
        }
    }
    
    // FilterFactory //

    class FilterFactory
    {
        static public function get ($name, $arguments)
        {
            $className = 'Ba7\\Framework\\Filter\\_' . $name;
            return new $className ($arguments);
        }
        
        /**
         *  $expression ::= ( '|' filterName ( ':' argument )* )*
        **/
        static public function parse ($expression)
        {
            if (! preg_match ('%^\\|(.*)(?:\\||$)%', $expression, $matches))
            {
                return new Filter;
            }
            
            $arguments = explode (':', $matches[1]);
            $filterName = array_shift ($arguments);
            $filter = self::get ($filterName, $arguments);
            
            $next = self::parse (substr ($expression, strlen ($matches[0])));
            if ($next)
            {
                $filter->append ($next);
            }
            
            return $filter;
        }
    }
}

namespace Ba7\Framework\Filter
{
    use Ba7\Framework\Filter, Ba7\Framework\FilterException;
    
    class _case extends Filter
    {
        // const //
        
        static protected $validValues = array ('upper', 'lower');
        
        // protected : Filter //
        
        protected function validate ($arguments)
        {
            if (count ($arguments) < 1)
            {
                throw new FilterException (
                    'Filter "case" must be provided with an argument, none given', 
                    FilterException::NOT_ENOUGH_ARGUMENTS
                );
            }
            if (! in_array ($arguments[0], self::$validValues))
            {
                throw new FilterException (
                    'Argument for filter "case" must be one of [' . implode (', ', self::$validValues) . '], ' .
                    '"' . $arguments[0] . '" given',
                    FilterException::ARGUMENT_VALUES_UNKNOWN
                );
            }
            return true;
        }
        
        protected function implement ($value)
        {
            if ($this->arguments[0] === 'upper')
            {
                return mb_strtoupper ($value);
            }
            if ($this->arguments[0] === 'lower')
            {
                return mb_strtolower ($value);
            }
            throw new FilterException (
                'Unreachable code', 
                FilterException::UNREACHABLE_CODE
            );
        }
    }
}
