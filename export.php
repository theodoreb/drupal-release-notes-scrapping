<?php

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Uri;
use ReleaseNotes\Commits;
use Gitlab\Client as GitlabClient;
use Gitlab\ResultPager;
use GuzzleHttp\Client;

function logg($message) {
  print "$message\n";
}

if (empty($argv[1])) {
  logg("Error: Drupal branch argument needed: export.php 10.0.0");
  exit;
}

$version = $argv[1];
list($major, $minor, $patch) = explode('.', $version);


const CORE_ID = 59858;
const GIT_API = 'https://git.drupalcode.org';
$gl = new GitlabClient();
$gl->setUrl(GIT_API);
$pager = new ResultPager($gl);

function nidFromCommit($commit) {
  if (preg_match('/^Issue #(\d+).* by ([^:]+):(.*)$/', $commit['title'], $matches)) {
    return $matches[1];
  }
  return FALSE;
}

logg("Fetching last tagged release from gitlab for the branch $major.$minor.x");
// Get last tagged version for the branch.
$last_tag = $gl->tags()->all(CORE_ID, ['search' => "^$major.$minor",])[0];


logg("Fetching all commits from gitlab for the branch $major.$minor.x since {$last_tag['commit']['created_at']}");
// Get all issues committed since the last tag.
$all_commits = $pager->fetchAll(new Commits($gl), 'all', [CORE_ID, ['ref_name' => "$major.$minor.x", 'since' => $last_tag['commit']['created_at']]]);
$all_committed_issues = array_filter(array_map('nidFromCommit', $all_commits));


const DO_API = 'https://www.drupal.org/api-d7';

function getPage(string $url) {
  $query = Query::parse((new Uri($url))->getQuery());
  return !empty($query['page']) ? (int) $query['page'] : 0;
}

function fetch($path, $query = []) {
  $url = Uri::withQueryValues(new Uri(DO_API . $path . '.json'), $query);
  $client = new Client();
  try {
    logg("Fetching $url");
    $response = $client->get($url);
  }
  catch (\Exception $exception) {
    dump($exception);
    return FALSE;
  }

  $data = json_decode($response->getBody());

  return (object) [
    'query' => $query,
    'list' => $data->list,
    'self' => getPage($data->self),
    'last' => getPage($data->last),
  ];
}

function fetchAll($path, $query) {
  $query += ['page' => 0];
  $list = [];
  // No pagination.
  do {
    $page = fetch($path, $query);
    $list = array_merge($list, $page->list);
    $query['page'] = $page->self + 1;
  } while ($page->self < $page->last);

  return $list;
}


logg("Fetching the tid from drupal.org for the term '$version release notes'");
// Get the tid of the release note tag from d.o
$tid = FALSE;
if ($terms = fetchAll( '/taxonomy_term', ['vocabulary' => 9, 'name' => "$version+release+notes"])) {
  if (!empty($terms)) {
    $tid = (int) $terms[0]->tid;
  }
}

if (!$tid) {
  logg("Error: The tag '$version release notes' can not be found on drupal.org");
  exit;
}

logg("Fetching all drupal core issues with the tid $tid");
// Get the list of core issues with the release note tag.
$all_do_issues = fetchAll('/node', [
  'field_project' => 3060,
  'type' => 'project_issue',
  'taxonomy_vocabulary_9' => $tid
]);

$committed_issues = array_intersect(array_column($all_do_issues, 'nid'), $all_committed_issues);

$issues_html = array_filter(array_map(function ($issue) use ($committed_issues) {
  if (!in_array($issue->nid, $committed_issues)) {
    return FALSE;
  }
  return [
    'nid' => $issue->nid,
    'title' => $issue->title,
    'html' => $issue->body->value,
  ];
}, $all_do_issues));

logg("Found " . count($issues_html) . " issues to add in the release notes");

$notes = [];
$missing = [];
$out = '';
foreach ($issues_html as $issue) {
  try {
    $node = html5qp($issue['html']);
  } catch (\Exception $e) {
    print_r($e->getMessage());
    print_r($issue['html']);
    die;
  }

  $title = $node->find("#summary-release-notes");
  if (!$title->length) {
    foreach ($node->find('h2,h3') as $candidate) {
      if (preg_match('/\brelease\b/i', $candidate->text())) {
        $title = $candidate;
      }
    }
    if (!$title->length) {
      $missing[] = $issue;
      continue;
    }
  }
  $next = '';
  if ($title->length) {
    $parts = [];
    foreach ($title->nextUntil('h2,h3') as $tag) {
      $parts[] = $tag->innerHTML();
    }
    $next = htmlentities(strip_tags(implode("\n", $parts), '<a><code>'));
  }
  if (!empty($next) && strlen($next) > 9) {
    $notes[] = nl2br("&lt;!--From <a href='https://drupal.org/i/$issue[nid]'>#$issue[nid] $issue[title]</a>-->\n\n$next");
  } else {
    $missing[] = $issue;
  }
}

$out .= <<<HEADER
<style>
body {max-width: 60em;margin:1em auto;font-family:sans-serif;}
</style>
<h1>Release notes for version $version</h1>
<p>
Issues committed to the $major.$minor.x branch <br>
since version {$last_tag['title']} released at {$last_tag['commit']['created_at']} <br>
with the tag 
<a href="https://www.drupal.org/project/issues/search?projects=Drupal+core&issue_tags=$version+release+notes">
  $version release notes</a>
</p>

HEADER;


$out .= nl2br("&lt;ul>\n\n&lt;li>\n") . implode(nl2br("\n&lt;/li>\n\n&lt;li>\n"), $notes) . nl2br("\n&lt;/li>\n\n&lt;/ul>");
$out .= nl2br("\n\n\nMissing release notes:\n") . implode(nl2br("\n"), array_map(function ($i) {
    return "<a href='https://drupal.org/i/$i[nid]'>#$i[nid] $i[title]</a>";
  }, $missing));


$release_note_path = __DIR__ . '/releasenotes';
if (!is_dir($release_note_path)) {
  mkdir($release_note_path);
}
// Open the file in a browser and copy/paste the result somewhere else.
file_put_contents("$release_note_path/$version.html", $out);
logg("\n\nFile generated, open in a browser: file://$release_note_path/$version.html\n\n");


