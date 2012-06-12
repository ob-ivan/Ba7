
Config
======

Introduction
------------

Almost each script you write relies on a set of constants that we
call configuration values. As soon as a script requires an external
resource such as a file on the hard disk, or a database connection,
it has to be supplied with the identification of this resource,
which turns out to be a number of string constants. The script may
also require behaviour constants such as whether it is run in debug
mode or not, or a timeout period for lengthy operations, or such.

For a stand-alone easily editable script called from a command line
it is possible to define those constants inside the script in its
first lines. But for a full-fledged application this is not usually
an option. To avoid lurking over all files that could define a
constant which you have to change urgnetly we take them out of
executable scope and put to a single (or one for each module) file
called configuration file, or config for short.

Among PHP programmers it became a common practice to write configs
in a form of an executable script. These have two kinds: array-style
and define-style.

    // Figure 1. Typical array-style config file in PHP.
    $globalConfig = array();
    $globalConfig['documentRoot']   = dirname (dirname (__FILE__));
    $globalConfig['cacheRoot']      = $globalConfig['documentRoot'] . '/cache';
    $globalConfig['templateRoot']   = $globalConfig['documentRoot'] . '/template';
    
    $globalConfig['useCache'] = true;
    
    $globalConfig['mysql'] = array (
        'host' => 'mysql.example.com',
        'port' => 3306,
        'user' => 'dbmaster',
        'pass' => 'A very valuable abracadabra',
        'db'   => 'forum',
    );
    
    $globalConfig['memcache'] = array (
        'host' => 'memcache.example.com',
        'port' => 11211
    );
    
The indisputable advantage of this style is its loading speed.
The price is that it defines a global variable. This means you will
have to include `global $globalConfig` in each function or method
that relies on the config. It also means that your config is
_mutable_, i.e. it can be changed at any point of your enormous
application leading to unpredictable results, and when trouble occurs
only holy `grep` will come to the rescue.

These two problems are solved when you switch config file to the
define-style.

    // Figure 2. Typical define-style config file in PHP.
    define ('DOCUMENT_ROOT', dirname (dirname (__FILE__)));
    define ('CACHE_ROOT',    DOCUMENT_ROOT . '/cache');
    define ('TEMPLATE_ROOT', DOCUMENT_ROOT . '/template');
    
    define ('USE_CACHE',    true);
    
    // PHP constants cannot be arrays. That's why you do something like this:
    define ('MYSQL_HOST', 'mysql.example.com');
    define ('MYSQL_PORT', 3306);
    define ('MYSQL_USER', 'dbmaster');
    define ('MYSQL_PASS', 'A very valuable abracadabra');
    define ('MYSQL_DB',   'forum');
    
    // ...or like this:
    define ('MEMCACHE', 'host=memcache.example.com,port=11211'

But again, this script populates global namespace with constant
names makeing them non-reusable in modules or external libs. The lack
of array suport is also a severe problem. I have actually encountered
configs which dealt with the issue in the following manner:

    // Figure 3. An unquestionable _don't_ for a config file.
    define ('MODULES', 'main|page|ajax|upload');
    define ('MODULES_ACTIONS', 'index:userInfo|show:getRss:edit|upload:captcha|form:result');
    // The string is later parsed inside Controller class.
    // Not to mention there were five times more modules and each had a dozen of actions
    // rendering the whole construction un-freaking-readable.
    
What's more to it, it is still an executable script. At some point
of development timeline you or one of your fellow contributors can
come to an idea of bringing some initialization operations other than
defining a config into the file. Like this:

    // Figure 4. An undoubted crime against common sense and moral laws.
    define ('MYSQL_HOST', 'mysql.example.com');
    define ('MYSQL_PORT', 3306);
    define ('MYSQL_USER', 'dbmaster');
    define ('MYSQL_PASS', 'A very valuable abracadabra');
    define ('MYSQL_DB',   'forum');
    mysql_pconnect (MYSQL_HOST . ':' . MYSQL_PORT, MYSQL_USER, MYSQL_PASS);
    mysql_select_db (MYSQL_DB);
    mysql_query ('SET NAMES UTF8');
    
Seems reasonable at the first glance (if your project does not
require more than a single mysql connection), but is a devil in a box
for your later collaborators and for you, too, when something goes
wrong with the charset and you frantically search all over the code
for anything that concerns charsets and of course you never think of
that an SQL query could be possibly executed inside a config!

That altogether brings us to what can be called the best practice of
configuration:

> A config is no more and no less than a set of constant strings.

That's why we put it in `httpd.conf`, `nginx.conf` et al., but not
inside the deamons' code. And that's why you should do the same
with your PHP application.

Config file syntax
------------------

The essential structure unit of each config file is of course an
assignment:

    // Figure 5. Key and its value.
    useCache = true
    // Equals can be replaced with a colon:
    useFileCache : false
    // ...or simply omitted:
    useMemCache true
    // You may use whatever syntax pleases you.

Each assignment must be placed at separate line as there is no
separator for value end other than a line break.
Keys cannot be duplicated, doing so will result in an exception.

With the assignment statement it is already possible to create
configs in their essence: a set of constant strings.
But it would be not as useful if it didn't allow values grouping:

    // Figure 6. A value is an array.
    mysql {
        # This is a comment line.
        # You don't have to put quotation marks around string values:
        host : mysql.example.com
        port : 3306
        user : dbmaster
        # However you are free to do so:
        pass : "A very valuable abracadabra"
        # Single quotes are also allowed:
        db   : 'forum'
    }
    # This key will not raise an exception as it is not in mysql
    # group, thus not a duplicate.
    user = My beautiful user
    
Sometimes you would like to reuse values defined earlier, like
`DOCUMENT_ROOT` in Fig.2. Then there is a $variables feature in
config file syntax:

    // Figure 7. Complex configuration file.
    ba7 : {
        $root = ba7
        # Variables are not exported by default. You will have to put an assignment
        # to export their values.
        root = $root
        # No concatenation sign is required. Values will be concatenated automatically.
        $framework = $root '/framework'
        bootstrap = $framework '/bootstrap.php'
        $class = $framework '/class'
        class : {
            # Variables defined at higher levels are available inside a subgroup.
            request  = $class '/Request.class.php'
            response = $class '/Response.class.php'
            # If no value is required, leave the key alone and it will be fine on its own:
            autoload
        }
        # Variables cannot be reassigned. This will throw an exception:
        $root = "It's party time!"
    }
    # $root variable in ba7 scope doesn't exist here anymore, so this is ok:
    $root = Party time I said!
    # ...and this is bad:
    framework = $framework
    memcache = {
        # Whether you want it or not, some key must be provided.
        # You can use numerals as placeholders.
        1 {
            host : memc1.example.com
            port : 11211
        }
        2 {
            host : memc2.example.com
            port : 11212
        }
        3 {
            host : memc3.example.com
            port : 11213
        }
    }
    
Though putting large portions of text into config is discouraged
(templates and dictionaries usually are best suited for that), it is
also possible to do so by placing a backslash `\` character at end of
each line. This can be especially useful for adding comments between
the lines:

    // Figure 8. Multiline value with comments.
    LoremIpsum = "Lorem ipsum dolor sit amet, consectetur adipisicing elit, " \
        # By command in Latin is too poor to understand this one:
        "sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. " \
        # I believe this will be enough for the first time.
        "Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris"
    
Interface and usage examples
----------------------------

The interface exposes its basic usage features:
 
    // Figure 9. The interface.
    interface Ba7\Framework\ConfigInterface
    extends
        ArrayAccess,   // allows you to foreach over it.
        Iterator       // allows you to access elements with the array-like brackets[] syntax.
    {
        public function __construct ($filePath = false);

        public function exists ($name);

        public function get ($name);

        public function __get ($name);
    }

The `__construct` method is called automatically when you instantiate
the class providing it an absolute path to the config file. After
that `exists` method can be called to check whether particular key
was defined by the file.

    // Figure 10. Instantiating Config and checking whether a key is set.
    use Ba7\Framework\Config;
    $config = new Config (dirname (__FILE__) . '/global.config');
    
    // Keys are case-sensitive, so make sure you spell them right.
    if ($config->exists ('debug'))
    {
        $debug = new Debug;
    }
    
There are three ways to access a value knowing its key: `get` method,
magic `__get` method and `ArrayAccess` interface.

    // Figure 11. Accessing config values by key.
    
    // You can call get() method explicitly.
    $useCache = $config->get('useCache');
    
    // You can access them as you would access object fields:
    $mysqlConfig = $config->mysql;
    // When access value is requested, Config returns its slice
    // which is also a Config instance.
    $mysqlHost = $mysqlConfig->get ('host');
    
    // You can use array-styled bracked syntax to access config values.
    $memcacheConfig = $config['memcache'];
    
Config keys can be iterated in a foreach loop:

    foreach ($memcacheConfig as $key => $value)
    {
        $connect = $value->host . ':' . $value->port;
        if (Memcache::testConnect ($connect))
        {
            Memcache::setConnect ($connect);
            break;
        }
    }
    
