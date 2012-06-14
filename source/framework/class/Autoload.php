<?php

namespace Ba7\Framework
{
    interface AutoloadInterface
    {
        static public function init ();

        static public function load ($className);

        static public function register ($classTemplate, $pathTemplate);
    }

    class AutoloadException extends \Exception
    {
        const FILE_DOES_NOT_EXIST               = __LINE__;
        const CLASS_OR_INTERFACE_DOES_NOT_EXIST = __LINE__;
    }
    
    class Autoload implements AutoloadInterface
    {
        // const //

        const CLASS_TEMPLATE        = __LINE__;
        const PATH_TEMPLATE         = __LINE__;
        const CLASS_TEMPLATE_LENGTH = __LINE__;

        // var //

        static protected $initDone = false;

        static protected $rules = array();

        // public //

        // Append Autoload::load to spl autoload stack.
        static public function init ()
        {
            if (self::$initDone)
            {
                return;
            }

            $local = __CLASS__ . '::load';
            if (! self::isSplRegistered($local))
            {
                spl_autoload_register ($local);
            }

            // Preserve original __autoload function.
            $original = '__autoload';
            if (function_exists ($original) && ! self::isSplRegistered ($original))
            {
                spl_autoload_register ($original);
            }

            self::$initDone = true;
        }

        static public function load ($className)
        {
            $path = false;

            foreach (self::$rules as $rule)
            {
                if ($rule->match ($className))
                {
                    $path = $rule->getPath();
                    break;
                }
            }

            if (! $path)
            {
                // $className is not registered. Let other autoloaders do their work.
                return;
            }

            if (! file_exists ($path))
            {
                throw new AutoloadException (
                    'Error autoloading class or interface "' . $className . '": ' .
                    'its associated file "' . $path . '" does not exist',
                    AutoloadException::FILE_DOES_NOT_EXIST
                );
            }

            require_once $path;

            if (! class_exists ($className) && ! interface_exists ($className))
            {
                throw new AutoloadException (
                    'Class or interface "' . $className . '" is not found ' .
                    'in its associated file "' . $path . '"',
                    AutoloadException::CLASS_OR_INTERFACE_DOES_NOT_EXIST
                );
            }
        }

        static public function register ($classTemplate, $pathTemplate)
        {
            self::$rules[] = new Autoload\Rule ($classTemplate, $pathTemplate);
            usort (self::$rules, __CLASS__ . '::sortLengthDesc');
        }

        // protected //

        static protected function isSplRegistered ($functionName)
        {
            $stack = spl_autoload_functions();
            if (! $stack)
            {
                return false;
            }
            foreach ($stack as $function)
            {
                // TODO: что делать, если в spl были зарегистрированы безымянные функции?
                if ($function === $functionName)
                {
                    return true;
                }
            }
            return false;
        }

        static protected function sortLengthDesc (Autoload\Rule $rule1, Autoload\Rule $rule2)
        {
            $length1 = $rule1->getLength();
            $length2 = $rule2->getLength();
            if ($length1 > $length2)
            {
                return -1;
            }
            if ($length1 < $length2)
            {
                return 1;
            }
            return 0;
        }
    }
}

namespace Ba7\Framework\Autoload
{
    use Ba7\Framework\FilterFactory;

    // Rule //
    
    interface RuleInterface
    {
        public function __construct ($classTemplate, $pathTemplate);

        public function getLength ();

        public function match ($className);

        public function getPath ();
    }

    class RuleException extends \Exception
    {
        const INFINITE_LOOP = __LINE__;
    }

    class Rule implements RuleInterface
    {
        // const //

        const REGEX_DELIMITER = '%';

        const CLASS_PATTERN_REGEX = '%
            \\[
                (                               # 1 alternatives:
                    (?: \\w*|\\\\ )             #   a word, a backslash, or an empty string.
                    (?:
                        \\| (?: \\w*|\\\\ )     #   the same, prefixed with a pipe
                    )*                          #   in arbitrary count.
                )
                (\\??)                          # 2 possibly a question mark.
            \\]
        %x';

        const PATH_PLACEHOLDER_REGEX = '%
            \\[
                ([^\\d\\]]*)            # 1 left context.
                ([1-9]\\d*)             # 2 pattern number.
                ([^\\d\\]\\|]*)         # 3 right context.
                ((?:                    # 4 filters:
                    \\| \\w+            #   a pipe and a filter name.
                    (?:
                        : [^|\\]:]+     #   arguments are separated by colons.
                    )*
                )*)
            \\]
        %x';

        // var : essentials //
        protected $classTemplate;
        protected $pathTemplate;

        // var : quick cache //
        protected $length;
        protected $classnameRegex;
        protected $pathTokens;

        // var : new for each run //
        protected $matches;

        // public : RuleInterface //

        public function __construct ($classTemplate, $pathTemplate)
        {
            $this->classTemplate = $classTemplate;
            $this->pathTemplate  = $pathTemplate;

            $this->length = strlen ($classTemplate);
        }

        public function getLength ()
        {
            return $this->length;
        }

        public function match ($className)
        {
            if (! $this->classnameRegex)
            {
                $this->prepareClassnameRegex();
            }

            if (preg_match ($this->classnameRegex, $className, $matches))
            {
                $this->matches = $matches;
                return true;
            }

            $this->matches = false;
            return false;
        }

        public function getPath ()
        {
            if (! $this->matches)
            {
                return false;
            }

            if (! $this->pathTokens)
            {
                $this->preparePathTokens();
            }
            
            $path = '';
            foreach ($this->pathTokens as $token)
            {
                if (is_string ($token))
                {
                    $path .= $token;
                }
                elseif ($token instanceof PathPlaceholderInterface)
                {
                    $n = $token->getNumber();
                    
                    if (! isset ($this->matches[$n]) || $this->matches[$n] == '')
                    {
                        continue;
                    }
                    
                    $replace = $token->getLeft() . strtr ($this->matches[$n], '\\', '/') . $token->getRight();
                    
                    $path .= $token->applyFilters ($replace);
                }
            }
            return $path;
        }

        // public : debug //

        public function __toString ()
        {
            return __CLASS__ . ' ("' . $this->classTemplate . '", "' . $this->pathTemplate . '")';
        }

        // protected //

        protected function prepareClassnameRegex ()
        {
            $classnameRegex = self::REGEX_DELIMITER . '^';
            foreach (self::split (self::CLASS_PATTERN_REGEX, $this->classTemplate, PREG_SPLIT_NO_EMPTY) as $token)
            {
                if (is_string ($token))
                {
                    $classnameRegex .= preg_quote ($token, self::REGEX_DELIMITER);
                }
                elseif (is_array ($token))
                {
                    $alts = array();
                    foreach (explode ('|', $token[1]) as $alt)
                    {
                        if (empty ($alt))
                        {
                            $alts[] = '\\w+';
                        }
                        elseif ($alt == '\\')
                        {
                            $alts[] = '[\\w\\\\]+';
                        }
                        else
                        {
                            $alts[] = $alt;
                        }
                    }
                    $subex = implode ('|', $alts);
                    if ($token[2])
                    {
                        $subex .= '|';
                    }
                    $classnameRegex .= '(' . $subex . ')';
                }
            }
            $classnameRegex .= '$' . self::REGEX_DELIMITER . 'i';

            $this->classnameRegex = $classnameRegex;
        }

        protected function preparePathTokens()
        {
            $pathTokens = array();
            
            foreach (self::split (self::PATH_PLACEHOLDER_REGEX, $this->pathTemplate, PREG_SPLIT_NO_EMPTY) as $token)
            {
                if (is_string ($token))
                {
                    $pathTokens[] = $token;
                }
                elseif (is_array ($token))
                {
                    $pathTokens[] = new PathPlaceholder ($token[1], $token[2], $token[3], $token[4]);
                }
            }
            
            $this->pathTokens = $pathTokens;
        }
        
        /**
         * Performs preg_split with delimiter capture in the manner that
         * unmatched parts of $string become strings in the output array
         * and matched parts become arrays of subpatterns in $regex.
         *
         *  @param  {integer}   flags   PREG_OFFSET_CAPTURE? | PREG_SPLIT_NO_EMPTY?
         *  @return array (
         *      0 => array ( // matched
         *          0 => $0, // whole matched pattern
         *          1 => $1, // subpattern 1
         *          ...
         *      ),
         *      1 => '...', // unmatched
         *      ...
         *  )
        **/
        static protected function split ($regex, $string, $flags = 0)
        {
            $return = array ();
            $offset = 0;
            $length = strlen ($string);
            while ($offset < $length)
            {
                if (! preg_match ($regex, $string, $matches, PREG_OFFSET_CAPTURE, $offset))
                {
                    // capture the last string part.
                    $return[] = substr ($string, $offset);
                    break;
                }

                $diff = $matches[0][1] - $offset;
                if (0 < $diff || ! ($diff || ($flags & PREG_SPLIT_NO_EMPTY)))
                {
                    // capture string part.
                    $return[] = substr ($string, $offset, $diff);
                }

                // capture matched part.
                if ($flags & PREG_OFFSET_CAPTURE)
                {
                    $return[] = $matches;
                }
                else
                {
                    $noOffset = array();
                    foreach ($matches as $index => $match)
                    {
                        $noOffset[$index] = $match[0];
                    }
                    $return[] = $noOffset;
                }

                // move the frame.
                $offset = $matches[0][1] + strlen ($matches[0][0]);
            }

            return $return;
        }
    }

    // PathPlaceholder //
    
    interface PathPlaceholderInterface
    {
        public function __construct ($left, $number, $right, $filterExpression);
        
        public function getLeft();
        public function getNumber();
        public function getRight();
        
        public function applyFilters ($value);
    }

    class PathPlaceholderException
    {
        const ZERO_IS_NOT_ALLOWED = __LINE__;
    }

    class PathPlaceholder implements PathPlaceholderInterface
    {
        // var //
        
        protected $left;
        protected $number;
        protected $right;
        protected $filter; // instanceof Filter
        
        // public : PathPlaceholderInterface //
        
        public function __construct ($left, $number, $right, $filterExpression)
        {
            $intval = intval ($number);
            if (! $intval)
            {
                throw new PathPlaceholderException (
                    'Number must be positive integer, "' . $number . '" given',
                    PathPlaceholderException::ZERO_IS_NOT_ALLOWED
                );
            }
            
            $this->left     = strval ($left);
            $this->number   = $intval;
            $this->right    = strval ($right);
            $this->filter   = FilterFactory::parse ($filterExpression);
        }
        
        public function getLeft()
        {
            return $this->left;
        }
        
        public function getNumber()
        {
            return $this->number;
        }
        
        public function getRight()
        {
            return $this->right;
        }
        
        public function applyFilters ($value)
        {
            $value = $this->filter->apply ($value);
            return $value;
        }
    }
}
