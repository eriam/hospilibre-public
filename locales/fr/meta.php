<?php

$locale_info = array(
  // As of https://encoding.spec.whatwg.org/ , windows-1252 is an alias of utf-8, even if it's a superset
  'charset' => "utf8",
  'first_day' => 1, // 0 = sunday, 1 = monday
  'alpha2' => 'fr',
  'alpha3' => 'fra'
);

$locale_info['names'] = array("fr_FR.".$locale_info['charset'], "fr_FR.UTF-8", "fr_FR.utf8", "fr_FR", "fra");
