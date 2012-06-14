<?php

namespace Ba7\Framework;

interface ConfigInterface
extends
    \ArrayAccess,   // allows you to foreach over it.
    \Iterator       // allows you to access elements with the array-like brackets[] syntax.
{
    /**
     * Reads config from file if filePath is given.
     * Otherwise creates empty Config instance. Used internally to extract config slices.
     *
     *  @param  {string}    $filePath
    **/
    public function __construct ($filePath = false);

    public function exists ($name);

    public function get ($name);

    // MUST work as alias for get().
    public function __get ($name);
}

class Config implements ConfigInterface
{
    // const //

    const VARIABLE_KEY_VALUE = __LINE__;
    const VARIABLE_KEY_LINE  = __LINE__;

    const TOKEN_KEY_TYPE  = __LINE__;
    const TOKEN_KEY_VALUE = __LINE__;

    const TOKEN_TYPE_BARE_STRING    = __LINE__;
    const TOKEN_TYPE_QUOTED_STRING  = __LINE__;
    const TOKEN_TYPE_VARIABLE_NAME  = __LINE__;

    // var //

    protected $items = array();
    protected $keys = array(); // index => key.
    protected $index = 0;
    protected $count = 0;

    // public : Iterator //

    public function current()
    {
        return $this->items[$this->keys[$this->index]];
    }

    public function key()
    {
        return $this->keys[$this->index];
    }

    public function next()
    {
        ++$this->index;
    }

    public function rewind()
    {
        $this->index = 0;
    }

    public function valid()
    {
        return $this->index < $this->count;
    }

    // public : ArrayAccess //

    public function offsetExists ($offset)
    {
        return isset ($this->items[$offset]);
    }

    public function offsetGet ($offset)
    {
        return $this->get ($offset);
    }

    public function offsetSet ($offset, $value)
    {
        throw new ConfigException ('Config values cannot be set', ConfigException::MUTATION_NOT_ALLOWED);
    }

    public function offsetUnset ($offset)
    {
        throw new ConfigException ('Config values cannot be unset', ConfigException::MUTATION_NOT_ALLOWED);
    }

    // public : ConfigInterface //

    public function __construct ($filePath = false)
    {
        if (! empty ($filePath))
        {
            if (! file_exists ($filePath))
            {
                throw new ConfigException (
                    'Config file ' . $filePath . ' does not exist',
                    ConfigException::FILE_DOES_NOT_EXIST
                );
            }
            $fileContents = file_get_contents ($filePath);
            try
            {
                $this->load ($fileContents);
            }
            catch (ConfigException $e)
            {
                throw new ConfigException (
                    'Error in config file ' . $filePath . ' : ' . $e->getMessage(),
                    $e->getCode()
                );
            }
            catch (\Exception $e)
            {
                throw new ConfigException (
                    'Error parsing config file ' . $filePath,
                    ConfigException::UNKNOWN_LOAD_ERROR,
                    $e
                );
            }
        }
    }

    public function exists ($name)
    {
        return isset ($this->items[$name]);
    }

    /**
     * Возвращает инстанс себя, если запрошена подгруппа.
     *
     * Возвращает false, если ключ отсутствует.
    **/
    public function get ($name)
    {
        if (isset ($this->items[$name]))
        {
            $value = $this->items[$name];
            if (is_array ($value))
            {
                $subConfig = new self();
                $subConfig->items = $value;
                $subConfig->rebuildIndex();
                return $subConfig;
            }
            return $value;
        }
        return false;
    }

    // Укороченная запись для нетерпеливых.
    public function __get ($name)
    {
        return $this->get($name);
    }

    public function __toString ()
    {
        return self::toString ($this->items);
    }

    // protected //

    protected function load ($text)
    {
        $this->items = self::parse (explode ("\n", $text));
        $this->rebuildIndex();
    }

    /**
     * Update index fields.
     *
     * Reads from items, writes to keys, index, count.
    **/
    protected function rebuildIndex()
    {
        $this->keys = array();
        $this->index = 0;
        $this->count = count ($this->items);
        foreach ($this->items as $key => $value)
        {
            $this->keys[] = $key;
        }
    }

    /**
     * Recursive parser.
    **/
    static protected function parse (
        $lines,
        &$lineNum = 0,  // pointer
        $depth = 0,
        $groupNameStack = array(),
        $variablesStack = array()
    ) {
        $items = array();

        /**
         * Variables stack.
         *
         * Variables from previous outer scope can be accessed.
         * When group closes, all its variables are discarded.
         *
         * Reassignment is not allowed at the same depth.
         * You may however assign variables with the same name at
         * lower depths.
         *
         *  array (
         *      <depth> => array (
         *          <varname> => array (
         *              self::VARIABLE_KEY_VALUE => <value>,
         *              self::VARIABLE_KEY_LINE  => <lineNum of declaration>,
         *          ),
         *          ...
         *      ),
         *      ...
         *  )
        **/
        $variablesStack[] = array ();

        while (isset ($lines[$lineNum]))
        {
            $line = trim ($lines[$lineNum]);
            ++$lineNum;

            // Empty lines and comment lines are ignored.
            if ($line == '' || $line[0] == '#')
            {
                continue;
            }

            // Is it a group end?
            if ($line == '}')
            {
                // Not allowed at the outmost level.
                if (! $depth)
                {
                    throw new ConfigException (
                        'Unexpected end of group (depth = 0) on line ' . $lineNum,
                        ConfigException::UNEXPECTED_END_OF_GROUP
                    );
                }

                return $items;
            }

            // Is it a variable declaration?
            $isVariable = false;
            if ($line[0] == '$')
            {
                $line = trim (substr ($line, 1));
                $isVariable = true;
            }

            // Key name or variable name is expected here.
            if (! preg_match ('/^\w+/i', $line, $matches))
            {
                throw new ConfigException (
                    ($isVariable ? 'Variable' : 'Key') . ' name is expected on line ' . $lineNum,
                    ConfigException::NAME_EXPECTED
                );
            }
            $name = $matches[0];
            $line = trim (substr ($line, strlen ($name)));

            // Neither variable nor key names may be duplicated.
            if ($isVariable)
            {
                if (isset ($variablesStack[$depth][$name]))
                {
                    throw new ConfigException (
                        'Duplicate variable name $' . $name . ' at line ' . $lineNum . '; ' .
                        'previously declared at line ' . $variablesStack[$depth][$name][self::VARIABLE_KEY_LINE]
                    );
                }
            }
            else
            {
                if (isset ($items[$name]))
                {
                    throw new ConfigException (
                        'Duplicate config key ' . implode ('/', $groupNameStack) . '/' . $name . ' on line ' . $lineNum,
                        ConfigException::DUPLICATE_KEY
                    );
                }
            }

            // You may separate key names from their values by an equals sign (=) or a colon (:).
            if (isset ($line[0]) && ($line[0] == '=' || $line[0] == ':'))
            {
                $line = trim (substr ($line, 1));
            }

            // Is it a subgroup start?
            if ($line == '{')
            {
                if ($isVariable)
                {
                    // TODO: Add support for variables with array values.
                    throw new ConfigException (
                        'Array values for variables are not implemented yet',
                        ConfigException::VARIABLE_MUST_BE_STRING
                    );
                }

                $groupNameStack[] = $name;
                $items[$name] = self::parse ($lines, $lineNum, $depth + 1, $groupNameStack, $variablesStack);
                array_pop ($groupNameStack);

                continue;
            }

            /**
             * Now it must be a concat expression.
             *
             * May include:
             *  - bare words.
             *  - 'single-quoted' or "double-quoted" string.
             *  - $variable references.
             *  - line breaks \
             *
             *  $tokens = array (
             *      array (
             *          TOKEN_KEY_TYPE  => TOKEN_TYPE_*,
             *          TOKEN_KEY_VALUE => <string value>
             *      ),
             *      ...
             *  )
            **/
            $tokens = array ();
            while ($line != '')
            {
                if ($line == '\\')
                {
                    while (true)
                    {
                        if (! isset ($lines[$lineNum]))
                        {
                            throw new ConfigException (
                                'Unexpected end of file while reading ' .
                                implode ('/', $groupNameStack) . '/' . ($isVariable ? '$' : '') . $name,
                                ConfigException::UNEXPECTED_END_OF_FILE
                            );
                        }
                        $line = trim ($lines[$lineNum]);
                        ++$lineNum;
                        if ($line[0] != '#')
                        {
                            break;
                        }
                    }
                    continue;
                }

                // quoted string
                if ($line[0] == '"' || $line[0] == '\'')
                {
                    $quote = $line[0];
                    $line = substr ($line, 1);
                    $pos = strpos ($line, $quote);
                    if (false === $pos)
                    {
                        throw new ConfigException (
                            'Unmatched quote mark ' . $quote .
                            ' at line ' . $lineNum,
                            ConfigException::UNMATCHED_QUOTE
                        );
                    }
                    $value = substr ($line, 0, $pos);
                    $line = trim (substr ($line, $pos + 1));
                    $tokens[] = array (
                        self::TOKEN_KEY_TYPE  => self::TOKEN_TYPE_QUOTED_STRING,
                        self::TOKEN_KEY_VALUE => $value
                    );
                    continue;
                }

                // variable reference
                if ($line[0] == '$')
                {
                    $line = substr ($line, 1);
                    if (! preg_match ('/^\w+/', $line, $matches))
                    {
                        throw new ConfigException (
                            'Variable name expected after $ ' .
                            'at line ' . $lineNum,
                            ConfigException::NAME_EXPECTED
                        );
                    }
                    $value = $matches[0];
                    $line = trim (substr ($line, strlen ($value)));
                    $tokens[] = array (
                        self::TOKEN_KEY_TYPE  => self::TOKEN_TYPE_VARIABLE_NAME,
                        self::TOKEN_KEY_VALUE => $value,
                    );
                    continue;
                }

                // bare string
                if (preg_match ('/^\S+/', $line, $matches))
                {
                    $value = $matches[0];
                    $line = trim (substr ($line, strlen ($value)));
                    $tokens[] = array (
                        self::TOKEN_KEY_TYPE  => self::TOKEN_TYPE_BARE_STRING,
                        self::TOKEN_KEY_VALUE => $value,
                    );
                    continue;
                }

                throw new ConfigException (
                    'Unrecognized string token at line ' . $lineNum . ': ' . $line,
                    ConfigException::UNRECOGNIZED_TOKEN
                );
            }

            $stringArray = array();
            $oldBare = false;
            foreach ($tokens as $token)
            {
                $new = '';
                $newBare = $token[self::TOKEN_KEY_TYPE] == self::TOKEN_TYPE_BARE_STRING;
                if ($oldBare && $newBare)
                {
                    $new .= ' ';
                }
                $oldBare = $newBare;

                if ($token[self::TOKEN_KEY_TYPE] == self::TOKEN_TYPE_VARIABLE_NAME)
                {
                    // Seek for variable of this name in accessible scopes.
                    // Start with the current depth and go to the root.
                    $varname = $token[self::TOKEN_KEY_VALUE];
                    $found = false;
                    for ($seek = $depth; $seek >= 0; --$seek)
                    {
                        if (isset ($variablesStack[$seek][$varname]))
                        {
                            $found = true;
                            break;
                        }
                    }
                    if (! $found)
                    {
                        throw new ConfigException (
                            'Unknown variable $' . $varname . ' at line ' . $lineNum,
                            ConfigException::UNKNOWN_VARIABLE
                        );
                    }

                    $new .= $variablesStack[$seek][$varname][self::VARIABLE_KEY_VALUE];
                }
                else
                {
                    $new .= $token[self::TOKEN_KEY_VALUE];
                }

                $stringArray[] = $new;
            }
            $string = implode ('', $stringArray);

            if ($isVariable)
            {
                $variablesStack[$depth][$name] = array (
                    self::VARIABLE_KEY_VALUE => $string,
                    self::VARIABLE_KEY_LINE  => $lineNum,
                );
            }
            else
            {
                $items[$name] = $string;
            }
        }

        // Stack must be empty at the end of file.
        if ($depth > 0)
        {
            throw new ConfigException (
                'Unexpected end of file (depth > 0)',
                ConfigException::UNEXPECTED_END_OF_FILE
            );
        }

        return $items;
    }

    static protected function toString ($array, $depth = 0)
    {
        $return = '';
        $indent = str_repeat (' ', $depth * 4);
        foreach ($array as $key => $value)
        {
            $line = $indent . $key;

            if ($value == '')
            {
                $return .= $line . "\n";
                continue;
            }

            $line .= ' = ';

            if (is_array ($value))
            {
                $line .=
                    '{' . "\n" .
                        self::toString ($value, $depth + 1).
                    $indent . '}' . "\n"
                ;
                $return .= $line;
                continue;
            }

            $line .= '"' . $value . '"';
            $return .= $line . "\n";
        }
        return $return;
    }
}

class ConfigException extends \Exception
{
    const MUTATION_NOT_ALLOWED      = __LINE__;
    const FILE_DOES_NOT_EXIST       = __LINE__;
    const UNKNOWN_LOAD_ERROR        = __LINE__;
    const UNEXPECTED_END_OF_GROUP   = __LINE__;
    const NAME_EXPECTED             = __LINE__;
    const DUPLICATE_KEY             = __LINE__;
    const VARIABLE_MUST_BE_STRING   = __LINE__;
    const UNEXPECTED_END_OF_FILE    = __LINE__;
    const UNMATCHED_QUOTE           = __LINE__;
    const UNRECOGNIZED_TOKEN        = __LINE__;
    const UNKNOWN_VARIABLE          = __LINE__;
}
