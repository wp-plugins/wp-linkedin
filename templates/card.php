<div class="linkedin"><div class="card">
<div id="cartouche">
	<a href="<?php echo $profile->publicProfileUrl; ?>"><img class="picture alignleft" src="<?php echo $profile->pictureUrl; ?>" width="<?php echo $picture_width; ?>px"/></a>
	<div class="name"><a href="<?php echo $profile->publicProfileUrl; ?>"><?php echo $profile->firstName; ?> <?php echo $profile->lastName; ?></a></div>
	<div class="headline"><?php echo $profile->headline; ?></div>
</div>

<?php if (isset($profile->summary) && $summary_length): ?>
<div class="summary"><?php echo wpautop(wp_linkedin_excerpt($profile->summary, $summary_length)); ?></div>
<?php endif; ?>

</div></div>
