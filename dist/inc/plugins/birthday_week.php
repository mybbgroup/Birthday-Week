<?php

if (!defined("IN_MYBB")) {
    die("Nice try but wrong place, smartass. Be a good boy and use navigation.");
}

$plugins->add_hook('datahandler_user_insert_end', 'birthday_week_cache');
$plugins->add_hook('datahandler_user_delete_end', 'birthday_week_cache');
$plugins->add_hook('datahandler_user_update', 'birthday_week_purgecache');
$plugins->add_hook('datahandler_user_clear_profile', 'birthday_week_purgecache');
$plugins->add_hook('datahandler_user_delete_content', 'birthday_week_purgecache');
$plugins->add_hook('index_end', 'birthday_week_populate');

function birthday_week_info()
{
    return array(
        'name' => 'Birthday Week',
        'description' => 'Display list of users in index page whose birthdays fall under current week.',
        'website' => 'https://github.com/mybbgroup/Birthday-Week',
        'author' => 'effone</a> of <a href="https://mybb.group">MyBBGroup</a>',
        'authorsite' => 'https://eff.one',
        'version' => '1.0.0',
        'compatibility' => '18*',
        'codename' => 'birthday_week',
    );
}

function birthday_week_activate()
{
    require MYBB_ROOT . "/inc/adminfunctions_templates.php";
    find_replace_templatesets('index_boardstats', '#{\$birthdays}#', '{$birthdays}<!-- birthday_week -->');

    birthday_week_cache();
}

function birthday_week_deactivate()
{
    require MYBB_ROOT . "/inc/adminfunctions_templates.php";
    find_replace_templatesets('index_boardstats', '#\<!--\sbirthday_week\s--\>#is', '', 0);

    birthday_week_purgecache();
}

function birthday_week_populate()
{
    global $mybb, $lang;
    if ($mybb->settings['showbirthdays']) {
        $birthdays = birthday_week_fetch();
        if (!empty($birthdays)) {
            global $templates;
            $birthweek = array();
            for ($i = 0; $i < 7; $i++) {
                $birthweek[] = date('j-n', strtotime(sprintf("this week +%u days", $i)));
            }
            $birthday_week_data = array_intersect_key($birthdays, array_flip($birthweek));
            $birthday_week_data = array_merge_recursive(...array_values($birthday_week_data));
            if (isset($birthday_week_data['hiddencount']) && is_array($birthday_week_data['hiddencount'])) {
                $birthday_week_data['hiddencount'] = array_sum($birthday_week_data['hiddencount']);
            }

            $bdays = $comma = '';
            $bdaycount = 0;
            $hiddencount = $birthday_week_data['hiddencount'];
            $this_week_birthdays = $birthday_week_data['users'];

            if (!empty($this_week_birthdays)) {
                if ((int)$mybb->settings['showbirthdayspostlimit'] > 0) {
                    $bdayusers = array();
                    foreach ($this_week_birthdays as $key => $bdayuser_pc) {
                        $bdayusers[$bdayuser_pc['uid']] = $key;
                    }

                    if (!empty($bdayusers)) {
                        global $db;
                        $bday_sql = implode(',', array_keys($bdayusers));
                        $query = $db->simple_select('users', 'uid, postnum', "uid IN ({$bday_sql})");

                        while ($bdayuser = $db->fetch_array($query)) {
                            if ($bdayuser['postnum'] < $mybb->settings['showbirthdayspostlimit']) {
                                unset($this_week_birthdays[$bdayusers[$bdayuser['uid']]]);
                            }
                        }
                    }
                }

                if (!empty($this_week_birthdays)) {
                    global $groupscache;
                    foreach ($this_week_birthdays as $bdayuser) {
                        if ($bdayuser['displaygroup'] == 0) {
                            $bdayuser['displaygroup'] = $bdayuser['usergroup'];
                        }

                        if ($groupscache[$bdayuser['displaygroup']] && $groupscache[$bdayuser['displaygroup']]['showinbirthdaylist'] != 1) {
                            continue;
                        }
                        // Manipulate to show day of the week in place of age here
                        $age = ' ('.date("D", strtotime('midnight', strtotime(sprintf("this week +%u days", array_search($bdayuser['bday'], $birthweek))))).')';

                        $bdayuser['username'] = format_name(htmlspecialchars_uni($bdayuser['username']), $bdayuser['usergroup'], $bdayuser['displaygroup']);
                        $bdayuser['profilelink'] = build_profile_link($bdayuser['username'], $bdayuser['uid']);
                        eval('$bdays .= "' . $templates->get('index_birthdays_birthday', 1, 0) . '";');
                        ++$bdaycount;
                        $comma = $lang->comma;
                    }
                }
            }

            if ($hiddencount > 0) {
                if ($bdaycount > 0) $bdays .= ' '.$lang->and.' ';
                $bdays .= "{$hiddencount} {$lang->birthdayhidden}";
            }

            if ($bdaycount > 0 || $hiddencount > 0) {
                global $boardstats;
                $lang->load('birthday_week');
                $lang->todays_birthdays = $lang->birthday_week_heading;
                eval('$birthday_week = "' . $templates->get('index_birthdays') . '";');
                $boardstats = str_replace('<!-- birthday_week -->', $birthday_week, $boardstats);
            }
        }
    }
}

function birthday_week_fetch()
{
    global $cache;
    $birthdays = $cache->read('birthdays_weekly');
    $fetchcutoff = strtotime('midnight', strtotime("this week -1 days"));

    if (!is_array($birthdays) || $birthdays['fetchcutoff'] < $fetchcutoff) {
        $birthdays = birthday_week_cache();
    }
    unset($birthdays['fetchcutoff']);
    return $birthdays;
}

function birthday_week_cache()
{
    global $db, $cache;
    $birthdays = $birthweek = array();

    for ($i = -1; $i < 8; $i++) { // Consider extra days on both side for timezone difference 
        $birthweek[] = date('j-n', strtotime(sprintf("this week +%u days", $i)));
    }
    array_walk($birthweek, function (&$value, $key) {
        $value = "'" . $value . "-%'";
    });

    $where = 'birthday LIKE ' . implode(' OR birthday LIKE ', $birthweek);
    $query = $db->simple_select("users", "uid, username, usergroup, displaygroup, birthday, birthdayprivacy", $where);

    $fetchcutoff = strtotime('midnight', strtotime("this week -1 days"));
    $birthdays['fetchcutoff'] = $fetchcutoff;
    while ($birthday = $db->fetch_array($query)) {
        $birthday['bday'] = substr($birthday['birthday'], 0, strrpos($birthday['birthday'], "-"));
        if ($birthday['birthdayprivacy'] != 'all') {
            ++$birthdays[$birthday['bday']]['hiddencount'];
            continue;
        }
        unset($birthday['birthdayprivacy']);
        $birthdays[$birthday['bday']]['users'][] = $birthday;
    }
    $cache->update('birthdays_weekly', $birthdays);
    return $birthdays;
}

function birthday_week_purgecache()
{
    global $cache;
    $cache->delete('birthdays_weekly');
}
