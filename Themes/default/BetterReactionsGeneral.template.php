<?php
/**
 * Better Reactions & Likes - dedicated admin template.
 *
 * @package BetterReactions
 * @version 0.1.0
 */

function template_better_reactions_general_page()
{
	global $context, $txt, $scripturl;
	$s = $context['better_reactions_settings'];

	if (!empty($context['better_reactions_saved']))
		echo '<div class="infobox">', $txt['better_reactions_saved'], '</div>';

	echo '
	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=general" method="post">
		<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_general'], '</h3></div>
		<div class="windowbg">
			<p class="description">', $txt['better_reactions_general_intro'], '</p>
			<dl class="settings">
				<dt><strong>', $txt['better_reactions_enabled'], '</strong><br><span class="smalltext">', $txt['better_reactions_enabled_desc'], '</span></dt>
				<dd><input type="checkbox" name="better_reactions_enabled" value="1"', $s['enabled'] ? ' checked' : '', '></dd>
				<dt><strong>', $txt['better_reactions_native_like_bridge'], '</strong><br><span class="smalltext">', $txt['better_reactions_native_like_bridge_desc'], '</span></dt>
				<dd><input type="checkbox" name="better_reactions_native_like_bridge" value="1"', $s['native_like_bridge'] ? ' checked' : '', '></dd>
			</dl>
			<div class="righttext"><input type="submit" name="save" value="', $txt['save'], '" class="button"></div>
		</div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>

	<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_native_settings'], '</h3></div>
	<div class="windowbg">
		<p>', $txt['better_reactions_native_settings_desc'], '</p>
		<a class="button" href="', $scripturl, '?action=admin;area=featuresettings;sa=likes">', $txt['better_reactions_open_native_likes'], '</a>
	</div>';
}
