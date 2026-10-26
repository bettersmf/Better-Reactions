<?php
/** Better Reactions & Likes 0.1.0 - Advanced Features admin. */
if (!defined('SMF')) die('No direct access...');

function template_better_reactions_features_page()
{
	global $context, $txt, $scripturl;
	$types=$context['better_reactions_feature_types'];
	$staff=array_flip($context['better_reactions_staff_only']);
	$rules=$context['better_reactions_topic_state_rules'];
	$milestones=$context['better_reactions_milestones'];
	if (!empty($context['better_reactions_features_saved'])) echo '<div class="infobox">',$txt['better_reactions_features_saved'],'</div>';
	if (!empty($context['better_reactions_toolkit_installed'])) echo '<div class="infobox">',$txt['better_reactions_toolkit_installed'],'</div>';
	echo '<form action="',$scripturl,'?action=admin;area=betterreactions;sa=features" method="post">
	<div class="cat_bar"><h3 class="catbg">',$txt['better_reactions_staff_only_title'],'</h3></div>
	<div class="windowbg"><p class="description">',$txt['better_reactions_staff_only_desc'],'</p><p class="smalltext">',$txt['better_reactions_staff_vs_state_note'],'</p><p>';
	if (!empty($context['better_reactions_staff_toolkit_ready']))
		echo '<span class="button disabled" aria-disabled="true">',$txt['better_reactions_staff_toolkit_installed_button'],'</span> <span class="smalltext">',$txt['better_reactions_toolkit_installed'],'</span>';
	else
		echo '<button type="submit" name="install_staff_toolkit" value="1" class="button">',$txt['better_reactions_install_staff_toolkit'],'</button> <span class="smalltext">',$txt['better_reactions_install_staff_toolkit_desc'],'</span>';
	echo '</p></div>
	<table class="table_grid br-staff-only-table"><thead><tr class="title_bar"><th>',$txt['better_reactions_col_reaction'],'</th><th>',$txt['better_reactions_col_category'],'</th><th class="centercol">',$txt['better_reactions_staff_only_column'],'</th></tr></thead><tbody>';
	foreach($types as $key=>$type)
	{
		if(!empty($type['is_native_like'])) continue;
		$v=BetterReactions_GetVisual($type);
		$icon=$v['image']!==''?'<img src="'.htmlspecialchars($v['image'],ENT_QUOTES).'" alt="" style="width:22px;height:22px;object-fit:contain;vertical-align:middle">':htmlspecialchars($v['glyph'],ENT_QUOTES,'UTF-8');
		echo '<tr class="windowbg"><td>',$icon,' <strong>',htmlspecialchars($type['label'],ENT_QUOTES),'</strong></td><td>',htmlspecialchars(BetterReactions_GetReactionCategory($key),ENT_QUOTES),'</td><td class="centercol"><input type="checkbox" name="staff_only[',htmlspecialchars($key,ENT_QUOTES),']" value="1"',isset($staff[$key])?' checked':'',' aria-label="',htmlspecialchars(sprintf($txt['better_reactions_staff_only_aria'],$type['label']),ENT_QUOTES),'"></td></tr>';
	}
	echo '</tbody></table><div class="windowbg"><p class="smalltext">',$txt['better_reactions_staff_definition'],'</p></div>

	<div class="cat_bar br-feature-gap"><h3 class="catbg">',$txt['better_reactions_topic_states_title'],'</h3></div>
	<div class="windowbg"><p class="description">',$txt['better_reactions_topic_states_desc'],'</p><label><input type="checkbox" name="topic_states_enabled" value="1"',!empty($context['better_reactions_topic_states_enabled'])?' checked':'','> <strong>',$txt['better_reactions_topic_states_enable'],'</strong></label></div>
	<table class="table_grid"><thead><tr class="title_bar"><th>',$txt['better_reactions_col_reaction'],'</th><th class="centercol">',$txt['better_reactions_state_enabled'],'</th><th>',$txt['better_reactions_state_label'],'</th><th class="centercol">',$txt['better_reactions_state_threshold'],'</th></tr></thead><tbody>';
	foreach($types as $key=>$type){$v=BetterReactions_GetVisual($type);$icon=$v['image']!==''?'<img src="'.htmlspecialchars($v['image'],ENT_QUOTES).'" alt="" style="width:22px;height:22px;object-fit:contain;vertical-align:middle">':htmlspecialchars($v['glyph'],ENT_QUOTES,'UTF-8');$rule=isset($rules[$key])?$rules[$key]:array('label'=>$type['label'],'threshold'=>1);echo '<tr class="windowbg"><td>',$icon,' <strong>',htmlspecialchars($type['label'],ENT_QUOTES),'</strong></td><td class="centercol"><input type="checkbox" name="state_enabled[',htmlspecialchars($key,ENT_QUOTES),']" value="1"',isset($rules[$key])?' checked':'','></td><td><input type="text" name="state_label[',htmlspecialchars($key,ENT_QUOTES),']" maxlength="40" value="',htmlspecialchars($rule['label'],ENT_QUOTES),'" style="width:95%"></td><td class="centercol"><input type="number" min="1" max="100" name="state_threshold[',htmlspecialchars($key,ENT_QUOTES),']" value="',(int)$rule['threshold'],'" style="width:70px"></td></tr>';}
	echo '</tbody></table><div class="windowbg"><p class="smalltext">',$txt['better_reactions_topic_states_note'],'</p></div>

	<div class="cat_bar br-feature-gap"><h3 class="catbg">',$txt['better_reactions_milestones_title'],'</h3></div>
	<div class="windowbg"><p class="description">',$txt['better_reactions_milestones_desc'],'</p><label><input type="checkbox" name="milestones_enabled" value="1"',!empty($context['better_reactions_milestones_enabled'])?' checked':'','> <strong>',$txt['better_reactions_milestones_enable'],'</strong></label></div>
	<table class="table_grid"><thead><tr class="title_bar"><th class="centercol">',$txt['better_reactions_milestone_icon'],'</th><th>',$txt['better_reactions_milestone_name'],'</th><th class="centercol">',$txt['better_reactions_milestone_threshold'],'</th></tr></thead><tbody>';
	for($i=0;$i<4;$i++){ $m=isset($milestones[$i])?$milestones[$i]:array('icon'=>'🏅','label'=>'Milestone','threshold'=>(($i+1)*100)); echo '<tr class="windowbg"><td class="centercol"><input type="text" name="milestone_icon[',$i,']" maxlength="16" value="',htmlspecialchars($m['icon'],ENT_QUOTES,'UTF-8'),'" style="width:70px;text-align:center;font-size:1.3em"></td><td><input type="text" name="milestone_label[',$i,']" maxlength="60" value="',htmlspecialchars($m['label'],ENT_QUOTES),'" style="width:95%"></td><td class="centercol"><input type="number" min="1" max="1000000" name="milestone_threshold[',$i,']" value="',(int)$m['threshold'],'" style="width:100px"></td></tr>'; }
	echo '</tbody></table><div class="windowbg righttext"><button type="submit" name="save_features" value="1" class="button">',$txt['save'],'</button></div><input type="hidden" name="',$context['session_var'],'" value="',$context['session_id'],'"></form>';
}
