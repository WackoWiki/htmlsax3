# HTMLSax3

A modernized **PHP 8.5-compatible** port of the classic HTMLSax3 SAX-style parser for HTML/XML, maintained by the WackoWiki team. Drop-in compatible with the original API, fully strict-typed, namespace-clean, and free of legacy PEAR dependencies.

> Originally ported from Python to PHP by Alexander Zhukov, then refactored for PEAR by Harry Fuecks, with extensive contributions from the Sitepointforums Advanced PHP community.

---

## Features

- **PHP 8.5 ready** — strict types, constructor property promotion, match expressions, `readonly` properties where applicable, no legacy quirks.
- **Zero PEAR dependency** — the original `PEAR::raiseError()` calls have been replaced with native `InvalidArgumentException`.
- **PSR-4 autoloaded** under the `HTMLSax3\` namespace with sub-namespaces for `States\` and `Decorators\`.
- **Fully decorator-driven** options system — trim, case-folding, entity parsing, escape stripping, linefeed/tab breaks.
- **Lightweight** — no external dependencies, runs anywhere PHP 8.5+ runs.
- **Used in production** by [SafeHTML](https://wackowiki.org/doc/Dev/Projects/SafeHTML) and [WackoWiki](https://wackowiki.org).

---

## Installation

### Via Composer (recommended)

```bash
composer require wackowiki/htmlsax3
```

### As a local path repository in WackoWiki

In your WackoWiki `composer.json`:

```json
{
    "require": {
        "wackowiki/htmlsax3": "*"
    },
    "repositories": [
        {
            "type": "path",
            "url": "src/lib/HTMLSax3",
            "options": {
                "symlink": true
            }
        }
    ],
    "minimum-stability": "dev"
}
```

The `symlink: true` option means edits to your local `src/lib/HTMLSax3/` are picked up immediately without re-running `composer update`.

### Manual installation

If you can't use Composer, require the autoloader (or each file in dependency order):

```php
require_once 'src/helpers.php';
require_once 'src/HTMLSax3.php';
require_once 'src/NullHandler.php';
require_once 'src/States/StateParser.php';
require_once 'src/States/StartingState.php';
require_once 'src/States/TagState.php';
require_once 'src/States/OpeningTagState.php';
require_once 'src/States/ClosingTagState.php';
require_once 'src/States/EscapeState.php';
require_once 'src/States/PiState.php';
require_once 'src/States/JaspState.php';
require_once 'src/Decorators/CaseFolding.php';
require_once 'src/Decorators/Entities_Parsed.php';
require_once 'src/Decorators/Entities_Unparsed.php';
require_once 'src/Decorators/Escape_Stripper.php';
require_once 'src/Decorators/Linefeed.php';
require_once 'src/Decorators/Tab.php';
require_once 'src/Decorators/Trim.php';
```

---

## Namespace map

All classes live under `HTMLSax3\`:

| File | Namespace |
|------|-----------|
| `src/HTMLSax3.php` | `HTMLSax3` |
| `src/NullHandler.php` | `HTMLSax3` |
| `src/helpers.php` | `HTMLSax3` (provides the `HTMLSax3\tap()` function) |
| `src/States/StateParser.php` | `HTMLSax3\States` |
| `src/States/StartingState.php` | `HTMLSax3\States` |
| `src/States/ClosingTagState.php` | `HTMLSax3\States` |
| `src/States/EscapeState.php` | `HTMLSax3\States` |
| `src/States/JaspState.php` | `HTMLSax3\States` |
| `src/States/OpeningTagState.php` | `HTMLSax3\States` |
| `src/States/PiState.php` | `HTMLSax3\States` |
| `src/States/TagState.php` | `HTMLSax3\States` |
| `src/Decorators/CaseFolding.php` | `HTMLSax3\Decorators` |
| `src/Decorators/Entities_Parsed.php` | `HTMLSax3\Decorators` |
| `src/Decorators/Entities_Unparsed.php` | `HTMLSax3\Decorators` |
| `src/Decorators/Escape_Stripper.php` | `HTMLSax3\Decorators` |
| `src/Decorators/Linefeed.php` | `HTMLSax3\Decorators` |
| `src/Decorators/Tab.php` | `HTMLSax3\Decorators` |
| `src/Decorators/Trim.php` | `HTMLSax3\Decorators` |

The parser-state constants (`STATE_START`, `STATE_TAG`, `STATE_OPENING_TAG`, `STATE_CLOSING_TAG`, `STATE_ESCAPE`, `STATE_JASP`, `STATE_PI`, `STATE_STOP`) are declared as global constants at the top of `src/States/StateParser.php` for backward compatibility with code that referenced them directly.

---

## Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use HTMLSax3\HTMLSax3;

// Define a handler object containing your SAX callbacks.
$handler = new class {
    public function openHandler(HTMLSax3 $parser, string $tag, array $attrs): void
    {
        echo "open: <$tag>\n";
        print_r($attrs);
    }

    public function closeHandler(HTMLSax3 $parser, string $tag): void
    {
        echo "close: </$tag>\n";
    }

    public function dataHandler(HTMLSax3 $parser, string $data): void
    {
        echo 'data: ' . trim($data) . "\n";
    }

    public function escapeHandler(HTMLSax3 $parser, string $data): void
    {
        echo 'escape: ' . trim($data) . "\n";
    }

    public function piHandler(HTMLSax3 $parser, string $target, string $data): void
    {
        echo "pi: <?$target $data?>\n";
    }

    public function jaspHandler(HTMLSax3 $parser, string $data): void
    {
        echo "jasp: $data\n";
    }
};

$parser = new HTMLSax3();
$parser->set_object($handler);
$parser->set_element_handler('openHandler', 'closeHandler');
$parser->set_data_handler('dataHandler');
$parser->set_escape_handler('escapeHandler');
$parser->set_pi_handler('piHandler');
$parser->set_jasp_handler('jaspHandler');

$parser->parse('<p>Hello <em>world</em>!<!-- a comment --></p>');
```

**Output:**

```
data: 
open: <p>
data: Hello 
open: <em>
data: world
close: </em>
data: !
escape: <!-- a comment -->
close: </p>
```

---

## API reference

### `HTMLSax3` — the user-facing class

#### `__construct()`
Instantiates the parser, attaches a `NullHandler` as the default handler for every callback channel, and pre-creates all state objects.

#### `set_object(object &$object): true`
Attaches the user handler object. Stored by reference so that decorators can wrap it in place.

```php
$parser->set_object($myHandler);
```

#### `set_option(string $name, int $value = 1): true`
Switches parser options on or off. Throws `InvalidArgumentException` for unknown options.

| Option | Effect |
|--------|--------|
| `XML_OPTION_TRIM_DATA_NODES` | Trim whitespace from element data |
| `XML_OPTION_CASE_FOLDING` | Convert tag names to uppercase |
| `XML_OPTION_LINEFEED_BREAK` | Emit a separate data callback for each line |
| `XML_OPTION_TAB_BREAK` | Emit a separate data callback for each tab |
| `XML_OPTION_ENTITIES_UNPARSED` | Emit a separate data callback for each entity (raw) |
| `XML_OPTION_ENTITIES_PARSED` | Emit a separate data callback for each entity (decoded) |
| `XML_OPTION_STRIP_ESCAPES` | Strip `<!-- -->` and `<![CDATA[]]>` markers from escapes |

```php
$parser->set_option('XML_OPTION_CASE_FOLDING');
$parser->set_option('XML_OPTION_ENTITIES_PARSED');
$parser->set_option('XML_OPTION_LINEFEED_BREAK');
```

#### `set_data_handler(string $method): void`
Sets the name of the data handler method on the attached handler object.

#### `set_element_handler(string $open_method, string $close_method): void`
Sets the names of the open and close tag handler methods.

#### `set_pi_handler(string $method): void`
Sets the name of the processing-instruction handler method.

#### `set_escape_handler(string $method): void`
Sets the name of the XML-escape handler method (comments, CDATA, doctype).

#### `set_jasp_handler(string $method): void`
Sets the name of the JSP/ASP `<% %>` handler method.

#### `get_current_position(): int`
Returns the current cursor position inside the parsed document.

#### `get_length(): int`
Returns the length of the document being parsed.

#### `parse(string $data): void`
Begins parsing `$data`. All callbacks fire on the attached handler object.

---

### Handler object signature

```php
class MyHandler
{
    public function openHandler(HTMLSax3 $parser, string $tag, array $attrs): bool;
    public function closeHandler(HTMLSax3 $parser, string $tag): bool;
    public function dataHandler(HTMLSax3 $parser, string $data): bool;
    public function escapeHandler(HTMLSax3 $parser, string $data): bool;
    public function piHandler(HTMLSax3 $parser, string $target, string $data): bool;
    public function jaspHandler(HTMLSax3 $parser, string $data): bool;
}
```

Each callback should return `true`. Returning `false` does **not** halt parsing (this matches the original HTMLSax3 semantics).

---

## Migration from the original HTMLSax3

The public API is **fully preserved**. Existing handler code works without modification. The only breaking changes are:

| Change | Reason |
|--------|--------|
| Namespace added: `HTMLSax3\` with `HTMLSax3\States\` and `HTMLSax3\Decorators\` sub-namespaces | PSR-4 autoloading |
| `StateParser` moved from `src/StateParser.php` to `src/States/StateParser.php` (namespace `HTMLSax3\States`) | Co-location with the state classes it coordinates |
| `PEAR::raiseError()` → `InvalidArgumentException` | PEAR is unmaintained |
| Inconsistent `ScanCharacter()` / `IgnoreCharacter()` unified to lowercase | PHP 8 method-name conventions |
| `function DoNothing()` → `function DoNothing(mixed ...$_args): void` | Variadic handles every SAX callback arity |
| Strict types enabled | PHP 8.5 best practice |

If you have a non-Composer installation that referenced `HTMLSax3\StateParser` at the root, add this to your bootstrap:

```php
// Shim for legacy code that referenced the old root-namespace StateParser.
if (!class_exists('HTMLSax3\StateParser', false)) {
    class_alias(\HTMLSax3\States\StateParser::class, 'HTMLSax3\StateParser');
}
```

---

## Development

### Requirements

- PHP **8.5** or later
- Composer

### Setup

```bash
git clone https://github.com/WackoWiki/htmlsax3.git
cd htmlsax3
composer install
```

### Running tests

```bash
composer test              # PHPUnit
composer test:coverage     # With HTML coverage report
composer stan              # PHPStan at max level
composer cs-check          # Check code style (dry run)
composer cs-fix            # Auto-fix code style
composer phpcs             # PSR-12 sniffs
```

### Directory layout

```
src/
├── helpers.php                # HTMLSax3\tap() helper
├── HTMLSax3.php               # User-facing facade (namespace HTMLSax3)
├── NullHandler.php            # No-op default handler (namespace HTMLSax3)
├── Decorators/
│   ├── CaseFolding.php        # namespace HTMLSax3\Decorators
│   ├── Entities_Parsed.php
│   ├── Entities_Unparsed.php
│   ├── Escape_Stripper.php
│   ├── Linefeed.php
│   ├── Tab.php
│   └── Trim.php
└── States/
    ├── StateParser.php        # State-machine coordinator
    │                          # (namespace HTMLSax3\States;
    │                          #  also declares STATE_* constants)
    ├── ClosingTagState.php    # namespace HTMLSax3\States
    ├── EscapeState.php
    ├── JaspState.php
    ├── OpeningTagState.php
    ├── PiState.php
    ├── StartingState.php
    └── TagState.php
```

---

## Related projects

- **[SafeHTML](https://wackowiki.org/doc/Dev/Projects/SafeHTML)** — the HTML sanitizer that uses HTMLSax3 to strip dangerous markup. Maintained in the same monorepo.
- **[WackoWiki](https://wackowiki.org)** — the wiki engine that originally bundled this parser.

---

## Contributing

Pull requests welcome! Please ensure:

- All tests pass (`composer test`)
- PHPStan is clean (`composer stan`)
- Code style is consistent (`composer cs-check`)

For bug reports and feature requests, open an issue at <https://github.com/WackoWiki/htmlsax3/issues>.

---

## License

This project is licensed under the **BSD 3-Clause "New" or "Revised" License** — see the [LICENSE](LICENSE) file for details.

> **Historical note:** The original HTMLSax3 source carried a `PHP License 3.0` header. That license has been [retired by the PHP project](https://www.php.net/license/) and superseded by the **PHP License 4.0**, which is itself the **BSD 3-Clause License**. As this library is no longer part of the PEAR ecosystem, the BSD-3-Clause terms now govern all use, modification, and distribution. The original copyright holders are preserved in the `LICENSE` file for historical accuracy.

