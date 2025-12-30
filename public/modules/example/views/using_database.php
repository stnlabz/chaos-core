<?php
declare(strict_types=1);
?>

<section>
<small><em><a href="/example">Example Module</a> >> Using a database</em></small><br>
    <h1>Using a Database in a Module</h1>
    <p>
        This page shows how to wire a Chaos CMS module to a database table using
        the core <code>db</code> class. The goal is to keep things simple and readable.
    </p>
</section>

<section>
    <h2>1. A Simple Table Structure</h2>

    <p>For this example, imagine a module called <code>docs</code> that loads content from a table:</p>

<pre>
CREATE TABLE docs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug       VARCHAR(190) NOT NULL,
  title      VARCHAR(190) NOT NULL,
  body       MEDIUMTEXT   NOT NULL,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY slug_unique (slug)
);
</pre>

    <p>
        Each row is a document. The important field for routing is <code>slug</code>,
        because it appears in URLs like <code>/docs/view/{slug}</code>.
    </p>
</section>

<section>
    <h2>2. Using the <code>db</code> Core</h2>

    <p>
        Chaos CMS DB uses a simple <code>db</code> class that wraps <code>mysqli</code>.
        In a module, you can access it via <code>global $db;</code>.
    </p>

<pre>
&lt;?php
declare(strict_types=1);

global $db;

/**
 * Fetch all docs, newest first.
 *
 * @return array&lt;int,array&lt;string,mixed&gt;&gt;
 */
function docs_get_all(): array
{
    global $db;

    return $db->fetch_all("
        SELECT id, slug, title, body, updated_at
        FROM docs
        ORDER BY updated_at DESC
    ");
}

/**
 * Fetch a single doc by slug.
 *
 * @param string $slug
 * @return array&lt;string,mixed&gt;|null
 */
function docs_get(string $slug): ?array
{
    global $db;

    $link = $db->connect();
    $safe = $link-&gt;real_escape_string($slug);

    return $db->fetch("
        SELECT id, slug, title, body, updated_at
        FROM docs
        WHERE slug = '{$safe}'
        LIMIT 1
    ");
}
</pre>

    <p>
        The pattern is:
    </p>
    <ul>
        <li>use <code>global $db;</code></li>
        <li>escape values with <code>$link-&gt;real_escape_string()</code></li>
        <li>return arrays (or <code>null</code> when nothing is found)</li>
    </ul>
</section>

<section>
    <h2>3. Wiring This Into the Module Router</h2>

    <p>
        Inside <code>/app/modules/docs/main.php</code>, you can now use these helpers
        in your handlers.
    </p>

<pre>
function docs_index(): void
{
    $items = docs_get_all();

    echo '&lt;div class="container my-4"&gt;';
    echo '&lt;h1&gt;Docs&lt;/h1&gt;';

    if (!$items) {
        echo '&lt;p class="text-muted"&gt;No documents yet.&lt;/p&gt;';
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

    $doc = docs_get($slug);
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
        The router decides whether to call <code>docs_index()</code> or
        <code>docs_view($slug)</code>, and those functions talk to the database
        through the <code>db</code> core.
    </p>
</section>

<section>
    <h2>4. Summary</h2>
    <ul>
        <li>Create a simple table with a <code>slug</code> column.</li>
        <li>Use <code>global $db;</code> and the <code>fetch</code>/<code>fetch_all</code> helpers.</li>
        <li>Keep DB code in small functions like <code>docs_get_all()</code> and <code>docs_get()</code>.</li>
        <li>Let your module router call handlers that use those helpers.</li>
    </ul>

    <p>
        Next, you can compare this with the
        <a href="/example/view/json"><strong>JSON-based approach</strong></a>
        to see how the same idea works without a database.
    </p>
</section>

