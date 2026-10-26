<?php
/** Better Reactions & Likes 0.1.0 - preset administration. */

function template_better_reactions_presets_page()
{
	global $context, $txt, $scripturl;

	if (!empty($context['better_reactions_preset_applied']))
		echo '<div class="infobox">', sprintf($txt['better_reactions_preset_applied'], htmlspecialchars($context['better_reactions_preset_applied'], ENT_QUOTES)), '</div>';
	if (!empty($context['better_reactions_saved']))
		echo '<div class="infobox">', $txt['better_reactions_picker_saved'], '</div>';

	if ($context['better_reactions_active_preset'] === 'custom')
		echo '<div class="information">', $txt['better_reactions_preset_custom'], '</div>';

	echo '<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_presets'], '</h3></div>
	<div class="windowbg"><p class="description">', $txt['better_reactions_presets_intro'], '</p><div class="information">', $txt['better_reactions_presets_safe_note'], '</div></div>
	<div class="br-preset-grid">';

	foreach ($context['better_reactions_presets'] as $key => $preset)
	{
		$active = $context['better_reactions_active_preset'] === $key;
		echo '<form class="windowbg br-preset-card', $active ? ' br-preset-active' : '', '" action="', $scripturl, '?action=admin;area=betterreactions;sa=presets" method="post">
			<h3>', htmlspecialchars($preset['name'], ENT_QUOTES), $active ? ' <span class="br-preset-badge">' . $txt['better_reactions_preset_active'] . '</span>' : '', '</h3>
			<p>', htmlspecialchars($preset['description'], ENT_QUOTES), '</p><div class="br-preset-icons">';
		foreach ($preset['reactions'] as $reaction)
		{
			$icon = strpos($reaction[2], 'emoji:') === 0 ? substr($reaction[2], 6) : '👍';
			echo '<span title="', htmlspecialchars($reaction[1], ENT_QUOTES), '">', htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'), '</span>';
		}
		echo '</div><div class="righttext"><button type="submit" name="apply_preset" value="', htmlspecialchars($key, ENT_QUOTES), '" class="button"', $active ? ' disabled' : '', '>', $active ? $txt['better_reactions_preset_active'] : $txt['better_reactions_apply_preset'], '</button></div>
			<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '"></form>';
	}

	echo '</div>
	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=presets" method="post" style="margin-top:16px">
		<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_picker_settings'], '</h3></div>
		<div class="windowbg"><dl class="settings">
			<dt><strong>', $txt['better_reactions_picker_visible'], '</strong><br><span class="smalltext">', $txt['better_reactions_picker_visible_desc'], '</span></dt>
			<dd><input type="number" name="picker_visible" min="3" max="12" value="', (int) $context['better_reactions_picker_visible'], '" style="width:80px"></dd>
			<dt><strong>', $txt['better_reactions_picker_categories'], '</strong><br><span class="smalltext">', $txt['better_reactions_picker_categories_desc'], '</span></dt>
			<dd><input type="checkbox" name="picker_categories" value="1"', !empty($context['better_reactions_picker_categories']) ? ' checked' : '', '></dd>
		</dl><div class="righttext"><button class="button" type="submit" name="save_picker" value="1">', $txt['save'], '</button></div></div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>';
}
