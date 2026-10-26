<?php
/**
 * Better Reactions & Likes - dedicated admin template.
 *
 * @package BetterReactions
 * @version 0.1.0
 */

function template_better_reactions_display_page()
{
	global $context, $txt, $scripturl;
	$s = $context['better_reactions_display'];

	if (!empty($context['better_reactions_saved']))
		echo '<div class="infobox">', $txt['better_reactions_display_saved'], '</div>';

	echo '
	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=display" method="post">
		<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_display'], '</h3></div>
		<div class="windowbg">
			<p class="description">', $txt['better_reactions_display_intro'], '</p>
			<dl class="settings">
				<dt><strong>', $txt['better_reactions_show_counts'], '</strong><br><span class="smalltext">', $txt['better_reactions_show_counts_desc'], '</span></dt>
				<dd><input type="checkbox" name="better_reactions_show_counts" value="1"', $s['show_counts'] ? ' checked' : '', '></dd>
				<dt><strong>', $txt['better_reactions_show_who'], '</strong><br><span class="smalltext">', $txt['better_reactions_show_who_desc'], '</span></dt>
				<dd><input type="checkbox" name="better_reactions_show_who" value="1"', $s['show_who'] ? ' checked' : '', '></dd>
				<dt><strong>', $txt['better_reactions_profile_links'], '</strong><br><span class="smalltext">', $txt['better_reactions_profile_links_desc'], '</span></dt>
				<dd><input type="checkbox" name="better_reactions_profile_links" value="1"', $s['profile_links'] ? ' checked' : '', '></dd>
				<dt><strong>', $txt['better_reactions_who_hover'], '</strong><br><span class="smalltext">', $txt['better_reactions_who_hover_desc'], '</span></dt>
				<dd><input type="checkbox" name="better_reactions_who_hover" value="1"', $s['who_hover'] ? ' checked' : '', '></dd>
				<dt><strong>', $txt['better_reactions_show_labels'], '</strong><br><span class="smalltext">', $txt['better_reactions_show_labels_desc'], '</span></dt>
				<dd><input type="checkbox" name="better_reactions_show_labels" value="1"', $s['show_labels'] ? ' checked' : '', '></dd>
				<dt><strong>', $txt['better_reactions_animate_icons'], '</strong><br><span class="smalltext">', $txt['better_reactions_animate_icons_desc'], '</span></dt>
				<dd><input type="checkbox" name="better_reactions_animate_icons" value="1"', $s['animate_icons'] ? ' checked' : '', '></dd>
				<dt><strong>', $txt['better_reactions_icon_size'], '</strong><br><span class="smalltext">', $txt['better_reactions_icon_size_desc'], '</span></dt>
				<dd><select name="better_reactions_icon_size">
					<option value="compact"', $s['icon_size'] === 'compact' ? ' selected' : '', '>', $txt['better_reactions_icon_compact'], '</option>
					<option value="comfortable"', $s['icon_size'] === 'comfortable' ? ' selected' : '', '>', $txt['better_reactions_icon_comfortable'], '</option>
					<option value="large"', $s['icon_size'] === 'large' ? ' selected' : '', '>', $txt['better_reactions_icon_large'], '</option>
				</select></dd>
			</dl>
			<div class="information">', $txt['better_reactions_display_interaction_note'], '</div>
			<div class="righttext"><input type="submit" name="save" value="', $txt['save'], '" class="button"></div>
		</div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>';
}
