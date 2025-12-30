<?php
declare(strict_types=1);
?>

<section>
    <h1>Example Module</h1>
    <p>This module demonstrates how to build a module for the Chaos CMS.</p>
</section>

<section>
    <h2>How a Module-Level Router Thinks</h2>

    <p>For a module called <code>docs</code>, the URLs look like:</p>

    <ul>
        <li>/docs</li>
        <li>/docs/view/{slug}</li>
        <li>etc.</li>
    </ul>

    <p>Inside <code>/public/modules/docs/main.php</code> we do three things:</p>

    <ol>
        <li>
            <strong>Parse the URL into segments</strong><br>
            <pre>
$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$parts = array_values(array_filter(explode('/', trim($path, '/'))));

$moduleSlug = $parts[0] ?? ''; // 'docs'
$action     = $parts[1] ?? ''; // 'view'
$arg1       = $parts[2] ?? ''; // 'some-slug'
            </pre>
        </li>

        <li>
            <strong>Safety check: make sure we’re inside this module</strong><br>
            <pre>
if ($moduleSlug !== 'docs') {
    docs_not_found();
    return;
}
            </pre>
            <p>This prevents weirdness if the router gets included from another URL.</p>
        </li>

        <li>
            <strong>Dispatch based on <code>$action</code></strong><br>
            <pre>
switch ($action) {
    case '':
        docs_index();
        break;

    case 'view':
        docs_view($arg1);
        break;

    default:
        docs_not_found();
        break;
}
            </pre>
        </li>
    </ol>
</section>

<section>
    <h2>Showing Content is Easy</h2>

    <p>Suppose your module structure looks like this:</p>

<pre>
docs
├── main.php
├── meta.json
└── pages
</pre>

    <p>Add a link in your index:</p>
<pre>
echo '&lt;a href="/docs/view/page_one"&gt;Page One&lt;/a&gt;';
</pre>

    <p>In <code>/public/modules/docs/main.php</code>:</p>

<pre>
function docs_view(string $slug): void
{
    if ($slug === '') {
        docs_not_found();
        return;
    }

    if ($slug === 'page_one') {
        $file = __DIR__ . '/pages/page_one.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }

    docs_not_found();
}
</pre>

    <p>Then in <code>/pages/page_one.php</code>:</p>

<pre>
&lt;?php
declare(strict_types=1);
/** Page One */
?&gt;

&lt;h1&gt;Page One&lt;/h1&gt;
&lt;p&gt;Your content here.&lt;/p&gt;
</pre>

    <p>Now visit: <code>/docs/view/page_one</code></p>
</section>

<section>
    <h2>In Conclusion</h2>
    <p>This is the basic blueprint of a working Chaos CMS module.</p>
    <p>You can extend it with:</p>
    <ul>
        <li><a href="/example/view/database">Database examples</a></li>
        <li><a href="/example/view/json">JSON examples</a></li>
    </ul>
</section>

