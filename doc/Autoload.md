
Autoload
========

Introduction and reasoning
--------------------------

PHP 5 allows you to avoid listing of all includes that your
application may ever need (or may not) with a feature called
[autoloading](http://php.net/manual/en/language.oop5.autoload.php).
It also helps you improve performance of your multi-class application
as it lets your script to not load classes that are unnecessary
on some run.

In my practice I have encountered several ways of making use of
`__autoload` function. The first one is simple and straight-forward:

    // Figure 1. The simplest __autoload ever.
    function __autoload ($className)
    {
        include DOCUMENT_ROOT . '/class/' . $className . '.php';
    }
    
Its basic flaw is that it enforces you to put all your classes in the
same directory, which may be undesirable in case of a typical
multi-class application.

One more way of writing `__autoload` function takes us back to
full listing of classes and their residences.

    // Figure 2. The most conscious __autoload ever.
    function __autoload ($className)
    {
        $map = array (
            'Utils'         => LIBS_ROOT  . '/Utils.class.php',
            'Database'      => LIBS_ROOT  . '/Database.class.php',
            'Model'         => LIBS_ROOT  . '/Model.abstract.php',
            'CatModel'      => MODEL_ROOT . '/Cat.model.php',
            'KittenModel'   => MODEL_ROOT . '/Kitten.model.php',
        );
        
        if (isset ($map[$className]))
        {
            include $map[$className];
        }
    }
    
This style may be preferrable for many as it lets you control the
situation and to be sure that the script is not going to autoload any
class unless it is listed in the $map variable (meant as constant,
but PHP won't allow you have array constants). The performance
improvement of autoloading as opposed to immediate files including
still persists, which makes this kind of `__autoload` functions a
good choice for actual fast-response web sites.

But there is a feline hint in the above code. You may be familiar to
the Factory pattern of producing classes and objects with a variable
name. And maybe you would like to make use of polymorphism in an
abstract class somewhat like this:

    // Figure 3. A polymorphic method calling a factory.
    abstract class Module
    {
        protected $name;
        protected $view;
        
        public function __construct ($name)
        {
            $this->name = $name;
        }
        
        public function getView ($id)
        {
            if (! $this->view)
            {
                // This abstract method doesn't have to understand what current module's name is.
                $model = ModelFactory::get ($this->name);
                
                // Whatever $model is returned, it is subscripted to extend abstract Model class,
                // which guarantees it to have getData() method.
                $data = $model->getData ($id);
                
                $this->view = ModuleTemplater::compile ($this->name, $data);
            }
            return $this->view;
        }
    }

Then you would be definitely happy if you didn't have to list all
your models twice: in the `__autoload` function like in Fig.2 and in
your `ModelFactory` code. Instead you could say, hey `ModelFactory`,
take whatever code is supplied in the `MODEL_ROOT` directory and
instantiate it:

    // Figure 4. Implementing factory with includes.
    class ModelFactory
    {
        static public function get ($moduleName)
        {
            $filePath = MODEL_ROOT . '/' . $moduleName . '.model.php';
            include $filePath;
            $className = $moduleName . 'Model';
            return new $className;
            
            // Of course in real life you put caching of model instances
            // and success checks in this method.
        }
    }
    
So from the above code you see what your `ModelFactory` does...
actually it does autoloading! And it also has to know `MODEL_ROOT`
constant, which you may find unpreferrable. See, global constants
are like global variables. Once you put them in, your code gets
contaminated and is not likely to ever be cured from it.

And that's where `__autoload` comes back again, and you rewrite
`ModelFactory` in the way to utilize it:
    
    // Figure 4. Implementing factory with autoloading.
    class ModelFactory
    {
        // Okay, this time I am not that lazy.
        static protected $registry = array();
        
        static public function get ($moduleName)
        {
            if (! isset (self::$registry[$moduleName]))
            {
                $className = $moduleName . 'Model';
                $model = new $className;
                
                if (! $model instanceof Model)
                {
                    throw new Exception ('Class ' . $className . ' does not extend class Model');
                }
                
                self::$registry[$moduleName] = $model;
            }
            return self::$registry[$moduleName];
        }
    }
    
And instead of listing each Cat, Kitten, Tomcat, Pussy etc models in
the $map variable (of Fig.2), you allow `__autoload` to take care of
them automatically:
    
    // Figure 5. Introducing template matching into __autoload function.
    function __autoload ($className)
    {
        $libsRoot  = DOCUMENT_ROOT . '/libs/';
        $modelRoot = DOCUMENT_ROOT . '/model/';
        
        $map = array (
            'Utils'    => $libsRoot . '/Utils.class.php',
            'Database' => $libsRoot . '/Database.class.php',
            'Model'    => $libsRoot . '/Model.abstract.php',
        );
        
        if (isset ($map[$className]))
        {
            include $map[$className];
        }
        elseif (preg_match ('/(\w+)Model/', $className, $matches))
        {
            include $modelRoot . '/' . $matches[1] . '.model.php';
        }
    }
    
If you find this nicer than Fig.2 then you are on my side and we can go on.

Now let us assume we have more than one single series of
similarly-named classes. It is not that hard if you take a look on
the following piece of code, pasted from an actual project I am
working on.

    // Figure 6. The ugliest __autoload helper function ever.
    static public function getFilePath ($className)
    {
        // Namespace\Classname
        if (false !== ($backslashPos = strrpos ($className, '\\')))
        {
            // Substitute \ with /
            $libDir = LIB_DIRECTORY . '/' . strtr (substr ($className, 0, $backslashPos), '\\', '/');
            $className = substr ($className, $backslashPos + 1);
            
            // Interface
            if (preg_match ('/^(.*)Interface$/', $className, $matches))
            {
                return $libDir . '/interface/' . $matches[1] . '.interface.php';
            }
            
            // Exception
            if (preg_match ('/^(.*)Exception$/', $className, $matches))
            {
                $filename = 'exception.php';
                if (! empty ($matches[1]))
                {
                    $filename = $matches[1] . '.' . $filename;
                }
                return $libDir . '/exception/' . $filename;
            }
            
            // Type
            if (preg_match ('/^(.*)Type$/', $className, $matches))
            {
                return $libDir . '/type/' . $matches[1] . '.type.php';
            }
            
            // Class
            return $libDir . '/class/' . $className . '.class.php';
        }
        
        // Interface
        if (preg_match ('/^(.*)Interface$/', $className, $matches))
        {
            return INTERFACE_DIRECTORY . '/' . $matches[1] . '.interface.php';
        }
        
        // Exception
        if (preg_match ('/^(.*)Exception$/', $className, $matches))
        {
            return EXCEPTION_DIRECTORY . '/' . $matches[1] . '.exception.php';
        }
        
        // Application
        if (preg_match ('/^(.*)Application$/', $className, $matches))
        {
            return APPLICATION_DIRECTORY . '/' . $matches[1] . '.application.php';
        }
        
        // Model or Record
        if (preg_match ('/^(.*)Model$/', $className, $matches))
        {
            return MODEL_DIRECTORY . '/' . $matches[1] . '.model.php';
        }
        if (preg_match ('/^(.*)Record$/', $className, $matches))
        {
            return MODEL_DIRECTORY . '/' . $matches[1] . '.record.php';
        }
        
        // Page
        if (preg_match ('/^(.*)Page$/', $className, $matches))
        {
            return PAGE_DIRECTORY . '/' . $matches[1] . '.page.php';
        }
        
        // all other classes are kernel classes.
        return KERNEL_DIRECTORY . '/' . $className . '.class.php';
    }

The size and copy-pastiness of the above function are not its only
flaws. What it lacks badly is an ability to grow in a way other than
by editing its source code. And that's what I try to fix in my
Autoload utility.

Template syntax
---------------

As seen in Fig.6 in the previous section, templating use cases are
pretty simple in their structure. There usually is a keyword which
fixes the context of a class, and a wild-card for its task-specific
name. You may need both wild-cards that represent namespaces (may
contain a backslash `\`), and that represent non-breaking names
(and thus do not contain a backslash). These are shown in the
following way:

    // Figure 7. Mandatory classname tokens.
    $libsInterfaceClassnameTemplate = '[\]\[]Interface';
    // [\] - the wild-card for "namespace token".
    // []  - the wild-card for "word token".
    
You may also want some tokens to be possibly empty. Then you put
a self-explanatory question mark `?` inside the brackets to show your
intention:

    // Figure 8. Optional classname tokens.
    $libxExceptionClassnameTemplate = '[\?]\[?]Exception';
    // [\?] - the wild-card for "optional namespace token".
    // [?]  - the wild-card for "optional word token".

The purpose of optional tokens is to be able to include abstract or
generic classes in the same directory where more specific classes of
the same sort reside in. Consider following folder structure:

    // Figure 9. Example folder structure.
    lib/
        Oxt/                        // Namespace token takes us into this folder.
            class/
                Parse.class.php     // Files that do the main work throw exceptions at times
                ...                 // and require interfaces to ensure that they interact properly.
            exception/
                exception.php       // Generic Exception for non-specific tasks.
                                    // Or it could be abstract Exception class extending
                                    // standard Exception with magic features of your choice.
                Parse.exception.php // ParseException is specific to parse tasks
                                    // and should be treated separately in a catch block.
                Type.exception.php  // Knowing your error helps a lot. I assure you.
            interface/
                Parse.interface.php
                ...

Here it is only natural to make it possible to leave out the
specifier part of class name for Exception family.

Now that we have written class name templates (see Figs. 7, 8) and
expect them to match when an appropriate class is called, how do we
specify resulting path to be included by the autoloader? It is done
in almost the same way you write substitute strings when running
preg_replace() et al.

    // Figure 10. Path templates.
    $libsInterfacePathTemplate = DOCUMENT_ROOT . '/libs/[1]/interface/[2].interface.php';
    $libsExceptionPathTemplate = DOCUMENT_ROOT . '/[libs/1]/exception/[2.]interface.php';
    
The digits 1 and 2 here stand for token order numbers in the
corresponding classname template. The difference between those two
path templates is in that exception path template's tokens are
optional, and you may want them to leave out some parts of resulting
path when the corresponding class name token is matched to empty
string. This leave-out parts are enclosed with brackets that surround
placeholder digits and constitute a path template token. This syntax
and behaviour should resemble that of foobar2000 path formatting
rules, where you place brackets around anything that could be null
to avoid displaying unnecessary spaces and delimiters if it really is.

Thus, after we register autoloading rules with the values described
above:

    // Figure 11. Actual registering of autoload rules.
    Ba7\Framework\Autoload::register ($libsInterfaceClassnameTemplate, $libsInterfacePathTemplate);
    Ba7\Framework\Autoload::register ($libxExceptionClassnameTemplate, $libsExceptionPathTemplate);

calls to not yet loaded exceptions and interfaces are performed in
the expected manner:

    // Figure 12. Requested classes and their resulting paths.
    
    // Rule: '[\]\[]Interface' => DOCUMENT_ROOT . '/libs/[1]/interface/[2].interface.php'
    Oxt\ParseInterface       => libs/Oxt/interface/Parse.interface.php
    Acase\FormatterInterface => libs/Acase/interface/Formatter.interface.php
    
    // Rule: '[\?]\[?]Exception' => DOCUMENT_ROOT . '/[libs/1]/exception/[2.]interface.php'
    Oxt\ParseException  => libs/Oxt/exception/Parse.exception.php
    Oxt\Exception       => libs/Oxt/exception/exception.php         // token 2 is omitted.
    KernelException     => exeption/Kernel.exception.php            // token 1 is omitted.
    // Here we can't get both tokens omitted as it would mean we called class Exception which is built-in.
    
To sum up and put some formality into it, here is description of
template syntax in a relaxed BNF-like form:

    // Figure 13. Template syntax.
    ClassnameTemplate ::= ClassnameToken+;
    ClassnameToken    ::= String | ClassnameWildcard;
    ClassnameWildcard ::= '[' '\'? '?'? ']';
    // Place '\' to allow matching backslash.
    // Place '?' to allow matching empty line.
    
    PathTemplate    ::= PathToken+;
    PathToken       ::= String | PathPlaceholder;
    PathPlaceholder ::= '[' PlaceholderContext Digit PlaceholderContext ']';
    PlaceholderContext ::= (any string, possibly empty, not containing digits and brackets)
    
Interface and usage examples
----------------------------

The interface exposes three public methods:

    // Figure 14. The interface.
    interface Ba7\Framework\AutoloadInterface
    {
        static public function init ();
        static public function load ($className);
        static public function register ($classTemplate, $pathTemplate);
    }

The `init` method performs some magic so that PHP becomes informed of
its existance and calls it when a need arises. This method is
performed automatically when you enable Ba7\Framework via boostrap
script. If you run Autoload as a separate utility, you will have to
call `init` from your caller script.

    // Figure 15. Running Autoload as a stand-alone utility.
    include BA7_DIRECTORY . '/framework/class/Autoload.class.php';
    Ba7\Framework\Autoload::init();

> The `init` method doesn't actually declare `__autoload` function.
> Instead it calls `spl_autoload_register` as it is aware of other
> autoloaders that can possibly exist in your application.

The `load` method is the one that PHP calls when your script requests
a class not yet loaded. As the method is public, you may want to call
it manually, but in fact you don't need to.

The most useful method that you will call the most is the `register`
method. Its arguments are classname and path templates described in
the previous section. You can start with registering simple rules
like:

    // Figure 15. Rearrangment of __autoload function from Figure 2.
    use Ba7\Framework\Autoload;
    
    Autoload::register ('Utils',    LIBS_ROOT . '/Utils.class.php');
    Autoload::register ('Database', LIBS_ROOT . '/Database.class.php');
    Autoload::register ('Model',    LIBS_ROOT . '/Model.class.php');
    // I consider putting on todo list an alternative classname token
    // that would allow you to write the above in this way:
    // Autoload::register ('[Utils|Database|Model]', LIBS_ROOT . '/[1].class.php');
    
    Autoload::register ('[]Model', MODEL_ROOT . '/[1].model.php');
    // [] here is not optional as it would override the rule for 'Model'.
    // It is good for you to know that the `load` method checks the rules
    // in decreasing length order, i.e. the longest (the most specific)
    // rules come first. Once a rule is matched, others (shorter ones)
    // are ignored. That's why class Model would match '[?]Model' template
    // rather than the simple 'Model' template.
    // A shorter but not yet implemented form of writing the same is:
    // Autoload::register ('[Cat|Kitten]Model', MODEL_ROOT . '/[1].model.php');

The monstruous `getFilePath` function from Fig.6 gets replaced with
a number of lines:

    // Figure 16. A concise way to write Figure 6.
    use Ba7\Framework\Autoload;
    
    // libs
    Autoload::register('[\]\[]Interface',     LIB_DIRECTORY . '/[1]/interface/[2].interface.php');
    Autoload::register('[\]\[?]Exception',    LIB_DIRECTORY . '/[1]/exception/[2.]exception.php');
    Autoload::register('[\]\[]Type',          LIB_DIRECTORY . '/[1]/type/[2].type.php');
    Autoload::register('[\]\[]',              LIB_DIRECTORY . '/[1]/class/[2].class.php');
    
    // core
    Autoload::register('[]Interface',     INTERFACE_DIRECTORY     . '/[1].interface.php');
    Autoload::register('[]Exception',     EXCEPTION_DIRECTORY     . '/[1].exception.php');
    Autoload::register('[]Application',   APPLICATION_DIRECTORY   . '/[1].application.php');
    Autoload::register('[]Model',         MODEL_DIRECTORY         . '/[1].model.php');
    Autoload::register('[]Record',        MODEL_DIRECTORY         . '/[1].record.php');
    Autoload::register('[]Page',          PAGE_DIRECTORY          . '/[1].page.php');
    Autoload::register('[]',              KERNEL_DIRECTORY        . '/[1].class.php');

The three first lines from `// libs` part lead us to an idea of
adding case-switching filters to path placeholders:

    // Figure 17. Possible syntax for placeholder filters. Pipe `|` reminds us of bash process piping.
    Autoload::register('[\]\[?][Interface|Exception|Type]', LIB_DIRECTORY . '/[1]/[3|lower]/[2.][3|lower].php');
    // or Smarty-style:
    Autoload::register('[\]\[?][Interface|Exception|Type]', LIB_DIRECTORY . '/[1]/[3|case:lower]/[2.][3|case:lower].php');

