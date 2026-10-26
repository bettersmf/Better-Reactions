<?php
/**
 * Better Reactions & Likes - dedicated admin template.
 *
 * @package BetterReactions
 * @version 0.1.0
 */

function template_better_reactions_manager_page()
{
	global $context, $txt, $scripturl;

	if (!empty($context['better_reactions_saved'])) echo '<div class="infobox">', $txt['better_reactions_reactions_saved'], '</div>';
	if (!empty($context['better_reactions_added'])) echo '<div class="infobox">', $txt['better_reactions_reaction_added'], '</div>';
	if (!empty($context['better_reactions_removed'])) echo '<div class="infobox">', $txt['better_reactions_reaction_removed'], '</div>';
	if (!empty($context['better_reactions_archived'])) echo '<div class="infobox">', $txt['better_reactions_reaction_archived'], '</div>';
	if (!empty($context['better_reactions_error'])) echo '<div class="errorbox">', htmlspecialchars($context['better_reactions_error'], ENT_QUOTES), '</div>';

	echo '
	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=reactions" method="post" enctype="multipart/form-data">
		<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_admin_manager'], '</h3></div>
		<div class="windowbg"><p class="description">', $txt['better_reactions_manager_intro'], '</p><div class="information">', $txt['better_reactions_like_locked'], '</div></div>
		<datalist id="br-category-options"><option value="General"><option value="Positive"><option value="Fun"><option value="Support"><option value="Gaming"><option value="Staff"></datalist>
		<table class="table_grid"><thead><tr class="title_bar">
			<th>', $txt['better_reactions_col_reaction'], '</th><th>', $txt['better_reactions_col_label'], '</th><th>', $txt['better_reactions_col_category'], '</th><th>', $txt['better_reactions_col_icon'], '</th><th class="centercol">', $txt['better_reactions_col_enabled'], '</th><th class="centercol">', $txt['better_reactions_col_order'], '</th><th class="centercol">', $txt['better_reactions_col_action'], '</th>
		</tr></thead><tbody>';

	foreach ($context['better_reactions_types'] as $key => $type)
	{
		$native = !empty($type['is_native_like']);
		$visual = BetterReactions_GetVisual($type);
		$preview = $visual['image'] !== '' ? '<img src="' . htmlspecialchars($visual['image'], ENT_QUOTES) . '" alt="" style="width:28px;height:28px;object-fit:contain;vertical-align:middle">' : '<span style="font-size:1.5em;vertical-align:middle">' . htmlspecialchars($visual['glyph'], ENT_QUOTES) . '</span>';
		echo '<tr class="windowbg">
			<td>', $preview, ' <code>', htmlspecialchars($key, ENT_QUOTES), '</code>', $native ? ' <span class="smalltext">(' . $txt['better_reactions_native'] . ')</span>' : '', '</td>
			<td><input type="text" name="label[', htmlspecialchars($key, ENT_QUOTES), ']" value="', htmlspecialchars($type['label'], ENT_QUOTES), '" maxlength="80"></td>
			<td><input type="text" name="category[', htmlspecialchars($key, ENT_QUOTES), ']" value="', htmlspecialchars(BetterReactions_GetReactionCategory($key), ENT_QUOTES), '" maxlength="40" list="br-category-options" style="width:110px"></td>
			<td>';
		if ($native)
		{
			echo '<span class="smalltext">', $txt['better_reactions_native_icon_locked'], '</span>';
		}
		else
		{
			$editor_glyph = $visual['image'] === '' ? $visual['glyph'] : '';
			echo '<input type="text" name="emoji[', htmlspecialchars($key, ENT_QUOTES), ']" value="', htmlspecialchars(BetterReactions_NormalizeAdminText($editor_glyph), ENT_QUOTES, 'UTF-8'), '" maxlength="32" placeholder="❤️" style="width:70px"> <span class="smalltext">', $txt['better_reactions_or_upload'], '</span> <input type="file" name="icon_upload[', htmlspecialchars($key, ENT_QUOTES), ']" accept="image/png,image/jpeg,image/gif,image/webp">';
		}
		echo '</td>
			<td class="centercol"><input type="checkbox" name="enabled[', htmlspecialchars($key, ENT_QUOTES), ']" value="1"', !empty($type['enabled']) ? ' checked' : '', $native ? ' disabled' : '', '>', $native ? '<input type="hidden" name="enabled[' . htmlspecialchars($key, ENT_QUOTES) . ']" value="1">' : '', '</td>
			<td class="centercol"><input type="number" name="sort[', htmlspecialchars($key, ENT_QUOTES), ']" value="', (int)$type['sort_order'], '" min="10" max="999" style="width:75px"', $native ? ' readonly' : '', '></td>
			<td class="centercol">', $native ? '—' : '<button type="submit" name="remove_reaction" value="' . htmlspecialchars($key, ENT_QUOTES) . '" class="button" onclick="return confirm(\'' . addslashes($txt['better_reactions_remove_confirm']) . '\');">' . $txt['better_reactions_remove'] . '</button>', '</td>
		</tr>';
	}

	echo '</tbody></table><div class="windowbg righttext"><input type="submit" name="save" value="', $txt['save'], '" class="button"></div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>

	<form action="', $scripturl, '?action=admin;area=betterreactions;sa=reactions" method="post" enctype="multipart/form-data" style="margin-top:16px">
		<div class="cat_bar"><h3 class="catbg">', $txt['better_reactions_add_reaction'], '</h3></div>
		<div class="windowbg"><p class="description">', $txt['better_reactions_add_reaction_desc'], '</p>
			<dl class="settings">
				<dt><strong>', $txt['better_reactions_new_label'], '</strong></dt><dd><input type="text" name="new_label" maxlength="80" required></dd>
				<dt><strong>', $txt['better_reactions_new_key'], '</strong><br><span class="smalltext">', $txt['better_reactions_new_key_desc'], '</span></dt><dd><input type="text" name="new_key" maxlength="32" pattern="[A-Za-z0-9_-]+"></dd>
				<dt><strong>', $txt['better_reactions_new_category'], '</strong><br><span class="smalltext">', $txt['better_reactions_new_category_desc'], '</span></dt><dd><input type="text" name="new_category" value="General" maxlength="40" list="br-category-options"></dd>
				<dt><strong>', $txt['better_reactions_new_icon'], '</strong><br><span class="smalltext">', $txt['better_reactions_new_icon_desc'], '</span></dt><dd><input type="text" name="new_emoji" maxlength="32" placeholder="✨" style="width:90px"> ', $txt['better_reactions_or_upload'], ' <input type="file" name="new_icon_upload" accept="image/png,image/jpeg,image/gif,image/webp"></dd>
				<dt><strong>', $txt['better_reactions_col_order'], '</strong></dt><dd><input type="number" name="new_sort" value="100" min="20" max="999" style="width:90px"></dd>
			</dl>
			<div class="righttext"><button type="submit" name="add_reaction" value="1" class="button">', $txt['better_reactions_add'], '</button></div>
		</div><input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>';
}
