<?php
/**
 * Starter text for the "mosh-pit" agreement (CMP 0.40.0), loaded once by
 * CMP_Agreements::seed() when no agreement with this key exists yet. After
 * that, the site team edits it under Users → Event agreements; this file is
 * never read again for an existing agreement.
 *
 * A pet-play mosh on play mats. Adapted from the owner's Pet Event Code of
 * Conduct (Drive, 2026-08-26), the PS Pups "Play Mat Rules" sign and Chicago
 * Pet Patrol's mosh etiquette (owner, 2026-11-15), with the venue left
 * general. The short rules match the 24 x 36 in "Mosh Pit Rules" poster.
 *
 * Not legal advice: the assumption-of-risk and release section should be
 * reviewed by the organization's Ohio attorney before it is relied on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'title'       => 'Mosh pit rules',
	'intro'       => 'Before you get on the mats, read the rules and agree. It takes a minute and keeps every pup, pet and handler safe.',
	'renew_hours' => 12,
	'rules'       => array(
		'Scan the code and agree before you play.',
		'Get consent before you touch or play.',
		"Don't grab tails or other attachables.",
		'No nudity or sexual activity. Keep it social.',
		'Shoes off the mats.',
		'No sharp gear, jewelry or glass near the mats.',
		'Drinks stay off the mats.',
		'Not moshing? Step off the mats.',
		'15 minutes of play if others are waiting.',
		'No photos or video without permission.',
		'Monitors keep you safe. Follow their lead.',
	),
	'body'        => <<<'HTML'
<h3>Code of conduct</h3>
<p>The mosh is a social, playful space for pups, pets, handlers and friends from the Leather, kink and LGBTQIA+ communities. Our goal is play that is fun, consensual and as safe as roughhousing on the mats can be.</p>
<p>By joining the mosh you agree to these rules, the venue's rules and local laws, and directions from event hosts, mosh monitors and venue staff. Everyone shares responsibility for keeping it safe and respectful.</p>

<h3>Respect</h3>
<p>Treat every person with dignity and respect regardless of gender, gender identity or expression, sexual orientation, race, ethnicity, disability, body type, age, experience level, relationship structure, role, title, pet identity, kink or other personal characteristic.</p>
<p>Pup, pet, handler, owner, trainer, alpha or any other role or title does not create authority over another person without that person's consent.</p>
<p>Harassment, bullying, intimidation, discriminatory behavior, threats or deliberately making someone feel unsafe will not be tolerated.</p>

<h3>Consent and play boundaries</h3>
<ul>
<li>Always get clear consent before petting, hugging, wrestling, roughhousing or joining someone else's play.</li>
<li>Don't grab tails, ears, hoods, harnesses, collars or other attachables, and don't touch someone's gear without permission.</li>
<li>Being on the mats, wearing pet gear, crawling, barking or being in pet space is not consent to be touched.</li>
<li>Consent can be withdrawn at any time. If someone says no, stop, backs away or signals they're done, the play ends at once.</li>
<li>Play to the energy of the person you're with. Not everyone wants rough play, and that's fine.</li>
<li>The mosh is strictly social and playful. No nudity and no sexual activity.</li>
</ul>

<h3>Mats and gear</h3>
<ul>
<li>Shoes, sneakers, boots and other hard-soled footwear stay off the mats, to prevent injuries and keep the play area clean.</li>
<li>No spikes, sharp studs or protrusions on collars or hoods, jewelry that could catch or cut, or glass on or near the mats.</li>
<li>Soft knee pads are recommended for wrestling and crawling. Pet gear such as hoods, tails and harnesses is always welcome, never required.</li>
<li>Keep drinks off the mats. If you're not actively moshing, step off the mats.</li>
<li>15 minutes of play if others are waiting. Monitors may limit how many people are on the mats at once.</li>
<li>Respect any quiet or cuddle zones the event sets up.</li>
</ul>

<h3>Photography and privacy</h3>
<p>Don't take photos or video of anyone unless they've given you clear permission. Events take place in public-facing venues, so the organizers can't guarantee you won't be photographed by others; if staying anonymous matters to you, take your own precautions, such as a hood or mask. Never deliberately identify or "out" another attendee.</p>

<h3>Venue, alcohol and allergies</h3>
<p>Follow the venue's rules and local laws. Bar-hosted events are 21+ with valid ID. Drink responsibly: alcohol doesn't excuse crossing anyone's boundaries, and anyone noticeably impaired may be asked to sit out. Illegal drugs are prohibited. If you have an allergy, for example to pet treats being shared, tell a monitor.</p>

<h3>Assumption of risk and release</h3>
<p>I understand that moshing and pet play on the mats is a voluntary, physical activity with risks that can't be removed entirely, including bumps, bruises, scrapes, rug burn, strains, sprains, falls, collisions, lost or damaged gear, and other injury, some of which may be serious.</p>
<p>I understand that:</p>
<ul>
<li>My decision to join the mosh is my own, and I may step off the mats at any time.</li>
<li>I am responsible for knowing my own physical limits and any condition that could make play unsafe for me.</li>
<li>Monitors help keep the mosh safe but cannot watch everyone or prevent every injury.</li>
<li>Central Ohio Leather &amp; Beyond, the event hosts, volunteers and monitors, and the venue (the "Released Parties") do not guarantee that the mosh is free of risk.</li>
</ul>
<p>If I choose to join the mosh, I knowingly accept and assume the inherent and reasonably foreseeable risks of this play. To the fullest extent permitted by Ohio law, I release the Released Parties from claims arising from ordinary negligence connected with my voluntary participation.</p>
<p>Nothing in this agreement waives claims or liability that cannot lawfully be waived, including for intentional misconduct, willful or wanton misconduct, nonconsensual conduct or anything else Ohio law does not allow a participant to release.</p>

<h3>Mosh monitors and reporting concerns</h3>
<p>Flag down a mosh monitor, an event host or venue staff if you need help, want to report a boundary violation, or have an allergy concern. Reports are handled as discreetly as reasonably possible, but confidentiality can't be guaranteed when a safety concern, venue issue or legal duty requires sharing. Medical emergencies should be reported to venue staff right away. Event organizers are not a substitute for emergency services, law enforcement or medical care.</p>

<h3>Enforcement</h3>
<p>Hosts, monitors and venue management may step in when these rules are broken. Responses can include a reminder, a warning, being asked to leave the mats or the event, being barred from future events, or contacting emergency services or law enforcement. Violence, threats, harassment or nonconsensual contact may lead to immediate removal without warning.</p>

<p><strong>Consent. Respect. Safety. Community.</strong></p>
HTML
	,
);
