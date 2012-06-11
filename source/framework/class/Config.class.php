<?php

namespace Ba7\Framework;

interface ConfigInterface
extends
    ArrayAccess,    // allows you to foreach over it.
    Iterator        // allows you to access elements with the array-like brackets[] syntax.
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
                $this->parse ($fileContents);
            }
            catch (ConfigException $e)
            {
                throw new ConfigException (
                    'Error in config file ' . $filePath . ' : ' . $e->getMessage(),
                    $e->getCode()
                );
            }
            catch (\Exception)
            {
                throw new ConfigException (
                    'Error parsing config file ' . $filePath,
                    ConfigException::UNKNOWN_PARSE_ERROR,
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
    
    // protected //
    
    protected function parse ($text)
    {
        $lineNum = 0;
        
        // Open {groups} stack.
        $stack = array ();
        // Pointer to current position.
        $writeTo = &$this->items;
        
        foreach (explode ("\n", $text) as $line)
        {
            $line = trim ($line);
            ++$lineNum;
            
            // Empty lines and comments are ignored.
            if (preg_match ('/^(#|$)/i', $line))
            {
                continue;
            }
            
            // End of group is permitted here.
            if ($line == '}')
            {
                // Stack must be non-empty.
                if (count ($stack) < 1)
                {
                    throw new ConfigException (
                        'Unexpected end of group (stack is empty) on line ' . $lineNum,
                        ConfigException::UNEXPECTED_END_OF_GROUP
                    );
                }
                
                // Cut the stack and renew current position pointer.
                array_pop ($stack);
                $writeTo = &$this->items;
                foreach ($stack as $level)
                {
                    $writeTo = &$writeTo[$level];
                }
                continue;
            }
            
            // Only keys are allowed here.
            // TODO: add support for reusable $variables.
            if (! preg_match ('/^\w+/i', $line, $matches))
            {
                throw new ConfigException (
                    'Key name expected on line ' . $lineNum,
                    ConfigException::KEY_NAME_EXPECTED
                );
            }
            $key = $matches[0];
            
            // If the same key is already defined, I don't know what was expected.
            if (isset ($writeTo[$key]))
            {
                throw new ConfigException (
                    'Duplicate config key /' . implode ('/', $stack) . '/' . $key . ' on line ' . $lineNum,
                    ConfigException::DUPLICATE_KEY
                );
            }
            
            /**
             * Possible further values
             *  - nothing
             *  - literal value
             *  - subgroup start
            **/
            
            $line = trim (substr ($line, strlen ($key)));
            if ($line == '')
            {
                // Nothing is considered an empty string value.
                $writeTo[$key] = '';
                continue;
            }
            
            // You may separate key names from their values by an equals sign (=) or a colon (:).
            if ($line[0] == '=' || $line[0] == ':')
            {
                $line = trim (substr ($line, 1));
            }
            
            if ($line != '{')
            {
                // Literal string value.
                // TODO: add support for 'single-quoted', "double-quoted", and \
                // multiline syntax.
                $writeTo[$key] = $line;
                continue;
            }
            
            // A group start is now the only option.
            // Put it to the stack and move the pointer upon it.
            $stack[] = $key;
            $writeTo[$key] = array ();
            $writeTo = &$writeTo[$key];
        }
        
        // Stack must be empty at the end of file.
        if (count ($stack) > 0)
        {
            throw new ConfigException (
                'Unexpected end of file (stack is not empty)',
                ConfigException::UNEXPECTED_END_OF_FILE
            );
        }
        
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
}

class ConfigException extends \Exception
{
    const FILE_DOES_NOT_EXIST       = __LINE__;
    const UNKNOWN_PARSE_ERROR       = __LINE__;
    const DUPLICATE_KEY             = __LINE__;
    const MUTATION_NOT_ALLOWED      = __LINE__;
    const UNEXPECTED_END_OF_GROUP   = __LINE__;
    const KEY_NAME_EXPECTED         = __LINE__;
    const UNEXPECTED_END_OF_FILE    = __LINE__;
}
