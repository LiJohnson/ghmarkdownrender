<?php

define(GITHUB_PERSONAL_ACCESS_TOKEN, $_SERVER['GITHUB_PERSONAL_ACCESS_TOKEN'] ?  $_SERVER['GITHUB_PERSONAL_ACCESS_TOKEN'] : 'token');
define(DOCUMENT_ROOT, $_SERVER['MD_DOC_ROOT'] ?  $_SERVER['MD_DOC_ROOT'] : '/path/to/docroot');
define(MD_DOC_CACHE, $_SERVER['MD_DOC_CACHE'] ?  $_SERVER['MD_DOC_CACHE'] == 'true' : true);


class GitHubMarkdownRender {

	const API_URL = 'https://api.github.com/markdown/raw';
	const GITHUB_API_VERSION_ACCEPT = 'application/vnd.github.v3+json';
	const CONTENT_TYPE = 'text/x-markdown';
	const USER_AGENT = 'magnetikonline/ghmarkdownrender 1.0';
	const MARKDOWN_EXT = '.md';
	const CACHE_SESSION_KEY = 'ghmarkdownrender';

	public function execute() {

		// validate DOCUMENT_ROOT exists
		if (!is_dir(DOCUMENT_ROOT)) {
			$this->renderErrorMessage(
				'<p>Given <strong>DOCUMENT_ROOT</strong> of <strong>' . htmlspecialchars(DOCUMENT_ROOT) . '</strong> ' .
				'is not a valid directory, ensure it matches that of your local web server document root.</p>'
			);

			return;
		}

		// get requested local markdown page and check file exists
		if (($markdownFilePath = $this->getRequestedPageFilePath()) === false) {
			$this->renderErrorMessage(
				'<p>Unable to determine requested Markdown page.</p>' .
				'<p>URI must end with an <strong>' . self::MARKDOWN_EXT . '</strong> file extension.</p>'
			);

			return;
		}

		if (!is_file($markdownFilePath)) {
			// can't find markdown file on disk
			$this->renderErrorMessage(
				'<p>Unable to open <strong>' . htmlspecialchars($markdownFilePath) . '</strong></p>' .
				'<p>Ensure <strong>DOCUMENT_ROOT</strong> matches that of your local web server.</p>'
			);

			return;
		}

		// check PHP session for cached markdown response
		$html = $this->getMarkdownHtmlFromCache($markdownFilePath);
		if ($html !== false) {
			// render markdown HTML from cache
			echo(
				$this->getHtmlPageHeader() .
				$html .
				$this->getHtmlPageFooter('Rendered from cache')
			);

			return;
		}

		// make request to GitHub API passing markdown file source
		$response = $this->parseGitHubMarkdownResponse(
			$this->doGitHubMarkdownRequest(file_get_contents($markdownFilePath))
		);

		if (!$response['ok']) {
			// error calling API
			$this->renderErrorMessage(
				'<p>Unable to access GitHub API</p>' .
				'<ul>' .
					'<li>Check your <strong>GITHUB_PERSONAL_ACCESS_TOKEN</strong> is correct (maybe revoked?)</li>' .
					'<li>Is GitHub/GitHub API endpoint <strong>' . htmlspecialchars(self::API_URL) . '</strong> accessable?</li>' .
					'<li>Has rate limit been exceeded? If so, wait until next hour</li>' .
				'</ul>'
			);

			return;
		}

		// save markdown HTML back to cache
		$this->setMarkdownHtmlToCache($markdownFilePath,$response['html']);

		// render markdown HTML from API response
		echo(
			$this->getHtmlPageHeader() .
			$response['html'] .
			$this->getHtmlPageFooter(
				'Rendered from GitHub Markdown API. ' .
				'<strong>Rate limit:</strong> ' . $response['rateLimit'] . ' // ' .
				'<strong>Rate remain:</strong> ' . $response['rateRemain']
			)
		);
	}

	private function getRequestedPageFilePath() {

		// get request URI, strip any querystring from end (used to trigger Markdown rendering from web server rewrite rule)
		$requestURI = trim($_SERVER['REQUEST_URI']);
		$requestURI = preg_replace('/\?.+$/','',$requestURI);
		$requestURI = urldecode($requestURI);
		// request URI must end with self::MARKDOWN_EXT
		return (preg_match('/\\' . self::MARKDOWN_EXT . '$/',$requestURI))
			? DOCUMENT_ROOT . $requestURI
			: false;
	}

	private function renderErrorMessage($errorHtml) {

		echo(
			$this->getHtmlPageHeader() .
			'<h1>Error</h1>' .
			$errorHtml .
			$this->getHtmlPageFooter()
		);
	}

	private function getHtmlPageHeader() {

		return <<<EOT
<!DOCTYPE html>

<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta http-equiv="X-UA-Compatible" content="IE=Edge" />
	<meta name="viewport" content="width=device-width,initial-scale=1" />

	<title>GitHub Markdown render</title>
	<style>

/* https://raw.githubusercontent.com/revolunet/sublimetext-markdown-preview/master/github.css */
@font-face {
  font-family: octicons-anchor;
  src: url(data:font/woff;charset=utf-8;base64,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) format('woff');
}

* {
    box-sizing: border-box;
}

body {
    width: 980px;
    margin-right: auto;
    margin-left: auto;
}

body .markdown-body {
    padding: 45px;
    border: 1px solid #ddd;
    border-radius: 3px;
    word-wrap: break-word;
}

pre {
    font: 12px Consolas, "Liberation Mono", Menlo, Courier, monospace;
}

.markdown-body {
  -webkit-text-size-adjust: 100%;
  text-size-adjust: 100%;
  color: #333;
  font-family: "Helvetica Neue", Helvetica, "Segoe UI", Arial, freesans, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
  font-size: 16px;
  line-height: 1.6;
  word-wrap: break-word;
}

.markdown-body a {
  background-color: transparent;
}

.markdown-body a:active,
.markdown-body a:hover {
  outline: 0;
}

.markdown-body strong {
  font-weight: bold;
}

.markdown-body h1 {
  font-size: 2em;
  margin: 0.67em 0;
}

.markdown-body img {
  border: 0;
}

.markdown-body hr {
  box-sizing: content-box;
  height: 0;
}

.markdown-body pre {
  overflow: auto;
}

.markdown-body code,
.markdown-body kbd,
.markdown-body pre {
  font-family: monospace, monospace;
  font-size: 1em;
}

.markdown-body input {
  color: inherit;
  font: inherit;
  margin: 0;
}

.markdown-body html input[disabled] {
  cursor: default;
}

.markdown-body input {
  line-height: normal;
}

.markdown-body input[type="checkbox"] {
  box-sizing: border-box;
  padding: 0;
}

.markdown-body table {
  border-collapse: collapse;
  border-spacing: 0;
}

.markdown-body td,
.markdown-body th {
  padding: 0;
}

.markdown-body input {
  font: 13px / 1.4 Helvetica, arial, nimbussansl, liberationsans, freesans, clean, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
}

.markdown-body a {
  color: #4078c0;
  text-decoration: none;
}

.markdown-body a:hover,
.markdown-body a:active {
  text-decoration: underline;
}

.markdown-body hr {
  height: 0;
  margin: 15px 0;
  overflow: hidden;
  background: transparent;
  border: 0;
  border-bottom: 1px solid #ddd;
}

.markdown-body hr:before {
  display: table;
  content: "";
}

.markdown-body hr:after {
  display: table;
  clear: both;
  content: "";
}

.markdown-body h1,
.markdown-body h2,
.markdown-body h3,
.markdown-body h4,
.markdown-body h5,
.markdown-body h6 {
  margin-top: 15px;
  margin-bottom: 15px;
  line-height: 1.1;
}

.markdown-body h1 {
  font-size: 30px;
}

.markdown-body h2 {
  font-size: 21px;
}

.markdown-body h3 {
  font-size: 16px;
}

.markdown-body h4 {
  font-size: 14px;
}

.markdown-body h5 {
  font-size: 12px;
}

.markdown-body h6 {
  font-size: 11px;
}

.markdown-body blockquote {
  margin: 0;
}

.markdown-body ul,
.markdown-body ol {
  padding: 0;
  margin-top: 0;
  margin-bottom: 0;
}

.markdown-body ol ol,
.markdown-body ul ol {
  list-style-type: lower-roman;
}

.markdown-body ul ul ol,
.markdown-body ul ol ol,
.markdown-body ol ul ol,
.markdown-body ol ol ol {
  list-style-type: lower-alpha;
}

.markdown-body dd {
  margin-left: 0;
}

.markdown-body code {
  font-family: Consolas, "Liberation Mono", Menlo, Courier, monospace;
  font-size: 12px;
}

.markdown-body pre {
  margin-top: 0;
  margin-bottom: 0;
  font: 12px Consolas, "Liberation Mono", Menlo, Courier, monospace;
}

.markdown-body .select::-ms-expand {
  opacity: 0;
}

.markdown-body .octicon {
  font: normal normal normal 16px/1 octicons-anchor;
  display: inline-block;
  text-decoration: none;
  text-rendering: auto;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
  user-select: none;
}

.markdown-body .octicon-link:before {
  content: '\\f05c';
}

.markdown-body:before {
  display: table;
  content: "";
}

.markdown-body:after {
  display: table;
  clear: both;
  content: "";
}

.markdown-body>*:first-child {
  margin-top: 0 !important;
}

.markdown-body>*:last-child {
  margin-bottom: 0 !important;
}

.markdown-body a:not([href]) {
  color: inherit;
  text-decoration: none;
}

.markdown-body .anchor {
  display: inline-block;
  padding-right: 2px;
  margin-left: -18px;
}

.markdown-body .anchor:focus {
  outline: none;
}

.markdown-body h1,
.markdown-body h2,
.markdown-body h3,
.markdown-body h4,
.markdown-body h5,
.markdown-body h6 {
  margin-top: 1em;
  margin-bottom: 16px;
  font-weight: bold;
  line-height: 1.4;
}

.markdown-body h1 .octicon-link,
.markdown-body h2 .octicon-link,
.markdown-body h3 .octicon-link,
.markdown-body h4 .octicon-link,
.markdown-body h5 .octicon-link,
.markdown-body h6 .octicon-link {
  color: #000;
  vertical-align: middle;
  visibility: hidden;
}

.markdown-body h1:hover .anchor,
.markdown-body h2:hover .anchor,
.markdown-body h3:hover .anchor,
.markdown-body h4:hover .anchor,
.markdown-body h5:hover .anchor,
.markdown-body h6:hover .anchor {
  text-decoration: none;
}

.markdown-body h1:hover .anchor .octicon-link,
.markdown-body h2:hover .anchor .octicon-link,
.markdown-body h3:hover .anchor .octicon-link,
.markdown-body h4:hover .anchor .octicon-link,
.markdown-body h5:hover .anchor .octicon-link,
.markdown-body h6:hover .anchor .octicon-link {
  visibility: visible;
}

.markdown-body h1 {
  padding-bottom: 0.3em;
  font-size: 2.25em;
  line-height: 1.2;
  border-bottom: 1px solid #eee;
}

.markdown-body h1 .anchor {
  line-height: 1;
}

.markdown-body h2 {
  padding-bottom: 0.3em;
  font-size: 1.75em;
  line-height: 1.225;
  border-bottom: 1px solid #eee;
}

.markdown-body h2 .anchor {
  line-height: 1;
}

.markdown-body h3 {
  font-size: 1.5em;
  line-height: 1.43;
}

.markdown-body h3 .anchor {
  line-height: 1.2;
}

.markdown-body h4 {
  font-size: 1.25em;
}

.markdown-body h4 .anchor {
  line-height: 1.2;
}

.markdown-body h5 {
  font-size: 1em;
}

.markdown-body h5 .anchor {
  line-height: 1.1;
}

.markdown-body h6 {
  font-size: 1em;
  color: #777;
}

.markdown-body h6 .anchor {
  line-height: 1.1;
}

.markdown-body p,
.markdown-body blockquote,
.markdown-body ul,
.markdown-body ol,
.markdown-body dl,
.markdown-body table,
.markdown-body pre {
  margin-top: 0;
  margin-bottom: 16px;
}

.markdown-body hr {
  height: 4px;
  padding: 0;
  margin: 16px 0;
  background-color: #e7e7e7;
  border: 0 none;
}

.markdown-body ul,
.markdown-body ol {
  padding-left: 2em;
}

.markdown-body ul ul,
.markdown-body ul ol,
.markdown-body ol ol,
.markdown-body ol ul {
  margin-top: 0;
  margin-bottom: 0;
}

.markdown-body li>p {
  margin-top: 16px;
}

.markdown-body dl {
  padding: 0;
}

.markdown-body dl dt {
  padding: 0;
  margin-top: 16px;
  font-size: 1em;
  font-style: italic;
  font-weight: bold;
}

.markdown-body dl dd {
  padding: 0 16px;
  margin-bottom: 16px;
}

.markdown-body blockquote {
  padding: 0 15px;
  color: #777;
  border-left: 4px solid #ddd;
}

.markdown-body blockquote>:first-child {
  margin-top: 0;
}

.markdown-body blockquote>:last-child {
  margin-bottom: 0;
}

.markdown-body table {
  display: block;
  width: 100%;
  overflow: auto;
  word-break: normal;
  word-break: keep-all;
}

.markdown-body table th {
  font-weight: bold;
}

.markdown-body table th,
.markdown-body table td {
  padding: 6px 13px;
  border: 1px solid #ddd;
}

.markdown-body table tr {
  background-color: #fff;
  border-top: 1px solid #ccc;
}

.markdown-body table tr:nth-child(2n) {
  background-color: #f8f8f8;
}

.markdown-body img {
  max-width: 100%;
  box-sizing: content-box;
  background-color: #fff;
}

.markdown-body code {
  padding: 0;
  padding-top: 0.2em;
  padding-bottom: 0.2em;
  margin: 0;
  font-size: 85%;
  background-color: rgba(0,0,0,0.04);
  border-radius: 3px;
}

.markdown-body code:before,
.markdown-body code:after {
  letter-spacing: -0.2em;
  content: "\00a0";
}

.markdown-body pre>code {
  padding: 0;
  margin: 0;
  font-size: 100%;
  word-break: normal;
  white-space: pre;
  background: transparent;
  border: 0;
}

.markdown-body .highlight {
  margin-bottom: 16px;
}

.markdown-body .highlight pre,
.markdown-body pre {
  padding: 16px;
  overflow: auto;
  font-size: 85%;
  line-height: 1.45;
  background-color: #f7f7f7;
  border-radius: 3px;
}

.markdown-body .highlight pre {
  margin-bottom: 0;
  word-break: normal;
}

.markdown-body pre {
  word-wrap: normal;
}

.markdown-body pre code {
  display: inline;
  max-width: initial;
  padding: 0;
  margin: 0;
  overflow: initial;
  line-height: inherit;
  word-wrap: normal;
  background-color: transparent;
  border: 0;
}

.markdown-body pre code:before,
.markdown-body pre code:after {
  content: normal;
}

.markdown-body kbd {
  display: inline-block;
  padding: 3px 5px;
  font-size: 11px;
  line-height: 10px;
  color: #555;
  vertical-align: middle;
  background-color: #fcfcfc;
  border: solid 1px #ccc;
  border-bottom-color: #bbb;
  border-radius: 3px;
  box-shadow: inset 0 -1px 0 #bbb;
}

.markdown-body .pl-c {
  color: #969896;
}

.markdown-body .pl-c1,
.markdown-body .pl-s .pl-v {
  color: #0086b3;
}

.markdown-body .pl-e,
.markdown-body .pl-en {
  color: #795da3;
}

.markdown-body .pl-s .pl-s1,
.markdown-body .pl-smi {
  color: #333;
}

.markdown-body .pl-ent {
  color: #63a35c;
}

.markdown-body .pl-k {
  color: #a71d5d;
}

.markdown-body .pl-pds,
.markdown-body .pl-s,
.markdown-body .pl-s .pl-pse .pl-s1,
.markdown-body .pl-sr,
.markdown-body .pl-sr .pl-cce,
.markdown-body .pl-sr .pl-sra,
.markdown-body .pl-sr .pl-sre {
  color: #183691;
}

.markdown-body .pl-v {
  color: #ed6a43;
}

.markdown-body .pl-id {
  color: #b52a1d;
}

.markdown-body .pl-ii {
  background-color: #b52a1d;
  color: #f8f8f8;
}

.markdown-body .pl-sr .pl-cce {
  color: #63a35c;
  font-weight: bold;
}

.markdown-body .pl-ml {
  color: #693a17;
}

.markdown-body .pl-mh,
.markdown-body .pl-mh .pl-en,
.markdown-body .pl-ms {
  color: #1d3e81;
  font-weight: bold;
}

.markdown-body .pl-mq {
  color: #008080;
}

.markdown-body .pl-mi {
  color: #333;
  font-style: italic;
}

.markdown-body .pl-mb {
  color: #333;
  font-weight: bold;
}

.markdown-body .pl-md {
  background-color: #ffecec;
  color: #bd2c00;
}

.markdown-body .pl-mi1 {
  background-color: #eaffea;
  color: #55a532;
}

.markdown-body .pl-mdr {
  color: #795da3;
  font-weight: bold;
}

.markdown-body .pl-mo {
  color: #1d3e81;
}

.markdown-body kbd {
  display: inline-block;
  padding: 3px 5px;
  font: 11px Consolas, "Liberation Mono", Menlo, Courier, monospace;
  line-height: 10px;
  color: #555;
  vertical-align: middle;
  background-color: #fcfcfc;
  border: solid 1px #ccc;
  border-bottom-color: #bbb;
  border-radius: 3px;
  box-shadow: inset 0 -1px 0 #bbb;
}

.markdown-body .plan-price-unit {
  color: #767676;
  font-weight: normal;
}

.markdown-body .task-list-item {
  list-style-type: none;
}

.markdown-body .task-list-item+.task-list-item {
  margin-top: 3px;
}

.markdown-body .task-list-item input {
  margin: 0 0.35em 0.25em -1.6em;
  vertical-align: middle;
}

.markdown-body .plan-choice {
  padding: 15px;
  padding-left: 40px;
  display: block;
  border: 1px solid #e0e0e0;
  position: relative;
  font-weight: normal;
  background-color: #fafafa;
}

.markdown-body .plan-choice.open {
  background-color: #fff;
}

.markdown-body .plan-choice.open .plan-choice-seat-breakdown {
  display: block;
}

.markdown-body .plan-choice-free {
  border-radius: 3px 3px 0 0;
}

.markdown-body .plan-choice-paid {
  border-radius: 0 0 3px 3px;
  border-top: 0;
  margin-bottom: 20px;
}

.markdown-body .plan-choice-radio {
  position: absolute;
  left: 15px;
  top: 18px;
}

.markdown-body .plan-choice-exp {
  color: #999;
  font-size: 12px;
  margin-top: 5px;
}

.markdown-body .plan-choice-seat-breakdown {
  margin-top: 10px;
  display: none;
}

.markdown-body :checked+.radio-label {
  z-index: 1;
  position: relative;
  border-color: #4078c0;
}

		#footer {
			color: #777;
			font-size: 11px;
			margin: 10px auto;
			text-align: right;
			white-space: nowrap;
			width: 978px;
		}
	</style>
</head>

<body>

<div id="frame"><div id="markdown" class="markdown-body">
EOT;
	}

	private function getHtmlPageFooter($footerMessageHtml = false) {

		return
			'</div></div>' .
			(($footerMessageHtml !== false)
				? '<p id="footer">' . $footerMessageHtml . ' | <script>document.write("<a href="+location.href+"/raw >raw</a>");</script></p>'
				: ''
			) .
			'</body></html>';
	}

	private function getMarkdownHtmlFromCache($markdownFilePath) {
		if( !MD_DOC_CACHE )return false;
		// start session, look for file path in session space
		session_start();

		if (!isset($_SESSION[self::CACHE_SESSION_KEY][$markdownFilePath])) {
			// file path not found in cache
			return false;
		}

		// file path exists - compare file modification time to that in cache
		$cacheData = $_SESSION[self::CACHE_SESSION_KEY][$markdownFilePath];
		return ($cacheData['timestamp'] == filemtime($markdownFilePath))
			? $cacheData['html']
			: false;
	}

	private function setMarkdownHtmlToCache($markdownFilePath,$html) {
		if( !MD_DOC_CACHE )return false;

		if (!isset($_SESSION[self::CACHE_SESSION_KEY])) {
			// create new session cache structure
			$_SESSION[self::CACHE_SESSION_KEY] = [];
		}

		$_SESSION[self::CACHE_SESSION_KEY][$markdownFilePath] = [
			'timestamp' => filemtime($markdownFilePath),
			'html' => $html
		];
	}

	private function doGitHubMarkdownRequest($markdownSource) {

		$curl = curl_init();
		curl_setopt_array(
			$curl,
			[
				CURLOPT_HEADER => true,
				CURLOPT_HTTPHEADER => [
					'Accept: ' . self::GITHUB_API_VERSION_ACCEPT,
					'Authorization: token ' . GITHUB_PERSONAL_ACCESS_TOKEN,
					'Content-Type: ' . self::CONTENT_TYPE,
					'User-Agent: ' . self::USER_AGENT
				],
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $markdownSource,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_URL => self::API_URL
			]
		);

		$response = curl_exec($curl);
		curl_close($curl);

		return $response;
	}

	private function parseGitHubMarkdownResponse($response) {

		$seenHeader = false;
		$httpStatusOk = false;
		$rateLimit = 0;
		$rateRemain = 0;

		while (true) {
			// seek next CRLF, if not found bail out
			$nextEOLpos = strpos($response,"\r\n");
			if ($nextEOLpos === false) {
				// end of response hit
				break;
			}

			// extract header line and pop off from $response
			$headerLine = substr($response,0,$nextEOLpos);
			$response = substr($response,$nextEOLpos + 2);

			if ($seenHeader && (trim($headerLine) == '')) {
				// end of HTTP headers, bail out
				break;
			}

			if (!$seenHeader && preg_match('/^[a-zA-Z-]+:/',$headerLine)) {
				// have seen a header item - able to bail out once next blank line detected
				$seenHeader = true;
			}

			if (preg_match('/^Status: (\d+)/',$headerLine,$match)) {
				// save HTTP response status, expecting 200 (OK)
				$httpStatusOk = (intval($match[1]) == 200);
			}

			if (preg_match('/^X-RateLimit-Limit: (\d+)$/',$headerLine,$match)) {
				// save total allowed request count
				$rateLimit = intval($match[1]);
			}

			if (preg_match('/^X-RateLimit-Remaining: (\d+)$/',$headerLine,$match)) {
				// save request count remaining
				$rateRemain = intval($match[1]);
			}
		}

		// update id="" values of heading anchor elements from generated Markdown -> HTML
		$response = preg_replace(
			'/<a id="user-content-([^"]+)" class="anchor" href="/',
			'<a id="$1" class="anchor" href="',
			$response
		);

		return [
			'ok' => ($httpStatusOk && $rateLimit && $rateRemain),
			'rateLimit' => $rateLimit,
			'rateRemain' => $rateRemain,
			'html' => $response
		];
	}
}


(new GitHubMarkdownRender())->execute();
