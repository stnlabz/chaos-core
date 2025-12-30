<?php
declare(strict_types=1);
?>

<section>
    <small><em><a href="/example">Example Module</a> >> Using JSON</em></small><br>
    <h1>Using JSON in a Module</h1>
    <p>
        This page shows how to wire a Chaos CMS module to a simple JSON file,
        instead of a database. This is useful for small feature modules, demos,
        or places where you do not want to set up tables yet.
    </p>
</section>

<section>
    <h2>1. A Simple JSON Structure</h2>

    <p>For this example, imagine a module called <code>docs</code> with a data file:</p>

<pre>
/app/modules/docs/
├── main.php
└── data/
    └── docs.json
</pre>

    <p>The JSON file might look like this:</p>

<pre>
{
  "items": [
    {
      "slug": "intro",
      "title": "Introduction",
      "body": "This is the intro document."
    },
    {
      "slug": "chaos",
      "title": "Chaos and Order",
      "body": "Chaos CMS keeps the core simple and the logic clear."
    }
  ]
}
</pre>

    <p>
        Each item has a <code>slug</code>, <code>title</code>, and <code>body</code>.
        The <code>slug</code> appears in URLs like <code>/docs/view/{slug}</code>.
    </p>
</section>

<section>
    <h2>2. Loading JSON in the Module</h2>

    <p>
        Inside <code>/app/modules/docs/main.php</code>, you can create small helpers
        to load all documents or a single document by slug.
    </p>

<pre>
&lt;?php
declare(strict_types=1);

/**
 * Load all docs from JSON.
 *
 * @return array&lt;int,array&lt;string,mixed&gt;&gt;
 */
function docs_json_all(): array
{
    $file = __DIR__ . '/data/docs.json';

    if (!is_readable($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    return $data['items'] ?? [];
}

/**
 * Load a single doc by slug from JSON.
 *
 * @param string $slug
 * @return array&lt;string,mixed&gt;|null
 */
function docs_json_get(string $slug): ?array
{
    if ($slug === '') {
        return null;
    }

    $items = docs_json_all();
    foreach ($items as $item) {
        if (($item['slug'] ?? '') === $slug) {
            return $item;
        }
    }

    return null;
}
</pre>

    <p>
        Pattern:
    </p>
    <ul>
        <li>Read a file from <code>data/</code>.</li>
        <li><code>json_decode()</code> into an array.</li>
        <li>Return <code>[]</code> or <code>null</code> when things fail.</li>
    </ul>
</section>

<section>
    <h2>3. Wiring JSON into the Router</h2>

    <p>
        You can now use these helpers inside the same handlers
        that a database-backed module would use.
    </p>

<pre>
function docs_index(): void
{
    $items = docs_json_all();

    echo '&lt;div class="container my-4"&gt;';
    echo '&lt;h1&gt;Docs (JSON)&lt;/h1&gt;';

    if (!$items) {
        echo '&lt;p class="text-muted"&gt;No documents found.&lt;/p&gt;';
        echo '&lt;/div&gt;';
        return;
    }

    echo '&lt;ul&gt;';
    foreach ($items as $doc) {
        $slug  = htmlspecialchars($doc['slug'] ?? '', ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8');

        echo '&lt;li&gt;&lt;a href="/docs/view/' . $slug . '"&gt;' . $title . '&lt;/a&gt;&lt;/li&gt;';
    }
    echo '&lt;/ul&gt;';

    echo '&lt;/div&gt;';
}

function docs_view(string $slug): void
{
    if ($slug === '') {
        docs_not_found();
        return;
    }

    $doc = docs_json_get($slug);
    if (!is_array($doc)) {
        docs_not_found();
        return;
    }

    $title = htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8');
    $body  = nl2br(htmlspecialchars($doc['body'] ?? '', ENT_QUOTES, 'UTF-8'));

    echo '&lt;div class="container my-4"&gt;';
    echo '&lt;h1&gt;' . $title . '&lt;/h1&gt;';
    echo '&lt;div class="mt-3"&gt;' . $body . '&lt;/div&gt;';
    echo '&lt;/div&gt;';
}
</pre>

    <p>
        Notice that the router logic does not care where the data comes from.
        It only calls <code>docs_index()</code> or <code>docs_view($slug)</code>.
        The helpers inside those functions decide to use JSON.
    </p>
</section>

<section>
    <h2>4. Comparing JSON and Database</h2>

    <p>JSON is useful when:</p>
    <ul>
        <li>You want a simple module with only a few items.</li>
        <li>You do not want to manage tables yet.</li>
        <li>You like to edit data by hand in files.</li>
    </ul>

    <p>A database is useful when:</p>
    <ul>
        <li>You expect a lot of content or frequent changes.</li>
        <li>You want admin forms to create and edit items.</li>
        <li>You need search, filtering, or relationships.</li>
    </ul>

    <p>
        A real Chaos CMS module can even support both: start with JSON, then
        migrate to a database later by changing the data helpers.
    </p>

    <p>
        You can now go back to the
        <a href="/example/view/database"><strong>database example</strong></a>
        to compare the two approaches.
    </p>
</section>

