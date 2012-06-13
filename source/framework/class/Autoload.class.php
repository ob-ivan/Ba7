<?php

namespace Ba7\Framework;

// Autoload //

interface AutoloadInterface
{
    static public function init ();

    static public function load ($className);

    static public function register ($classTemplate, $pathTemplate);
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
        self::$rules[] = new AutoloadRule ($classTemplate, $pathTemplate);
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

    static protected function sortLengthDesc (AutoloadRule $rule1, AutoloadRule $rule2)
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

class AutoloadException extends \Exception
{
    const FILE_DOES_NOT_EXIST               = __LINE__;
    const CLASS_OR_INTERFACE_DOES_NOT_EXIST = __LINE__;
}

// AutoloadRule //

interface AutoloadRuleInterface
{
    public function __construct ($classTemplate, $pathTemplate);

    public function getLength ();

    public function match ($className);

    public function getPath ();
}

class AutoloadRule
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
            ([^\d\]]*)              # 1 left context.
            ([1-9]\d*)              # 2 pattern number.
            ([^\d\]]*)              # 3 right context.
            (                       # 4 filters:
                \\| \\w+            #   a pipe and filter name.
                (?:
                    : [^|\\]:]+     #   arguments are separated by colons.
                )*
            )*
        \\]
    %x';

    // var : essentials //
    protected $classTemplate;
    protected $pathTemplate;

    // var : quick cache //
    protected $length;
    protected $expression;
    protected $patternCount;

    // var : new for each run //
    protected $matches;

    // public : AutoloadRuleInterface //

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
        if (! $this->expression)
        {
            $this->prepareExpression();
        }

        if (preg_match ($this->expression, $className, $matches))
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

        $iteration = 0;
        $offset = 0;
        $path = $this->pathTemplate;
        while (preg_match (self::PATH_PLACEHOLDER_REGEX, $path, $matches, PREG_OFFSET_CAPTURE, $offset))
        {
            ++$iteration;
            if ($iteration > 100)
            {
                // Infinite loop.
                throw new AutoloadRuleException (
                    'Infinite loop while applying path template "' . $this->pathTemplate . '"',
                    AutoloadRuleException::INFINITE_LOOP
                );
            }

            /**
             * 1 = left context
             * 2 = number
             * 3 = right context
            **/
            $n = intval ($matches[2][0]);
            if (! $n)
            {
                // [0] is illegal. Move $offset to the end of it and go on.
                $offset = $matches[0][1] + strlen ($matches[0][0]);
                continue;
            }
            $replace = '';
            if (! empty ($this->matches[$n]))
            {
                $slashed = strtr ($this->matches[$n], '\\', '/');
                $replace = $matches[1][0] . $slashed . $matches[3][0];
            }
            $before = substr ($path, 0, $matches[0][1]);
            $after = substr ($path, $matches[0][1] + strlen ($matches[0][0]));
            $path = $before . $replace . $after;
            $offset = $matches[0][1] + strlen ($replace);
        }

        return $path;
    }

    // public : debug //

    public function __toString ()
    {
        return __CLASS__ . ' ("' . $this->classTemplate . '", "' . $this->pathTemplate . '")';
    }

    // protected //

    protected function prepareExpression ()
    {
        $patternCount = 0;
        $expression = self::REGEX_DELIMITER . '^';
        foreach (self::split (self::CLASS_PATTERN_REGEX, $this->classTemplate, PREG_SPLIT_NO_EMPTY) as $token)
        {
            if (is_array ($token))
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
                $expression .= '(' . $subex . ')';
                ++$patternCount;
            }
            elseif (is_string ($token))
            {
                $expression .= preg_quote ($token, self::REGEX_DELIMITER);
            }
        }
        $expression .= '$' . self::REGEX_DELIMITER . 'i';

        $this->patternCount = $patternCount;
        $this->expression = $expression;
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

class AutoloadRuleException extends \Exception
{
    const INFINITE_LOOP = __LINE__;
}

