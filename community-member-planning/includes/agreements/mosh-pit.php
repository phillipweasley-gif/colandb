<?php
/**
 * Starter text for the "mosh-pit" agreement (CMP 0.40.0), loaded once by
 * CMP_Agreements::seed() when no agreement with this key exists yet. After
 * that, the site team edits it under Users → Event agreements; this file is
 * never read again for an existing agreement.
 *
 * Adapted from the owner's Pet Event Code of Conduct (Drive, 2026-08-26):
 * same sections and wording where they fit a mosh pit, with the venue left
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
	'intro'       => 'Before you enter the pit, read the rules and agree. It takes a minute and keeps everyone safe.',
	'renew_hours' => 12,
	'rules'       => array(
		'Scan the code and agree before you enter the pit.',
		'Only mosh with people who are moshing.',
		'Someone falls? Everyone stops and picks them up.',
		'No punches, kicks, elbows or headshots.',
		'Hands off. No grabbing bodies, harnesses or gear.',
		'No spikes, studs, chains or sharp gear in the pit.',
		'No drinks or glass in the pit.',
		'Respect the edge. Not everyone signed up to mosh.',
		'Tap out anytime. No explanation needed.',
		'Monitors keep you safe. Follow their directions.',
	),
	'body'        => <<<'HTML'
<h3>Code of conduct</h3>
<p>Our goal is a pit that is fun, consensual and as safe as a mosh pit can be, for the Leather, kink and LGBTQIA+ communities and everyone who joins us.</p>
<p>By entering the pit you agree to follow these rules, the venue's rules and directions from event hosts, monitors and venue staff. Everyone shares responsibility for keeping it safe and respectful.</p>

<h3>Respect</h3>
<p>Treat every person with dignity and respect regardless of gender, gender identity or expression, sexual orientation, race, ethnicity, disability, body type, age, experience level, relationship structure, role, title, kink or other personal characteristic.</p>
<p>No role, title or relationship gives anyone authority over another person in the pit without that person's consent.</p>
<p>Harassment, bullying, intimidation, discriminatory behavior, threats or deliberately making someone feel unsafe will not be tolerated.</p>

<h3>Consent and the pit</h3>
<ul>
<li>Being in the pit means you agree to the kind of contact a mosh pit involves: bumping, shoving shoulder to shoulder and moving with the crowd. It does not mean consent to anything else.</li>
<li>Only mosh with people who are moshing. People at the edge, dancing nearby or passing through have not agreed to contact.</li>
<li>Grabbing, groping, holding someone down, pulling on harnesses, collars, chains or other gear, or touching someone sexually is never part of moshing.</li>
<li>Consent can be withdrawn at any time. Anyone may step out, tap out or ask for space for any reason, without explaining.</li>
<li>If someone says no, stop, back off or signals they're done, the contact ends at once.</li>
</ul>

<h3>Keep it safe</h3>
<ul>
<li>No punches, kicks, elbows, headshots, stage diving or crowd surfing unless the event hosts allow it.</li>
<li>If someone falls, everyone near them stops and helps them up.</li>
<li>Keep spikes, studs, chains, buckles, cuffs and any other sharp or swinging gear out of the pit.</li>
<li>No drinks, glass or open containers in the pit.</li>
<li>Know your limits. Step out if you're hurt, overheated, dizzy or too intoxicated to look after yourself.</li>
<li>Monitors may pause the pit, limit how many people are in it or ask anyone to step out.</li>
</ul>

<h3>Alcohol and intoxication</h3>
<p>Drink responsibly. Alcohol doesn't excuse crossing anyone's boundaries. Anyone noticeably impaired may be asked to sit out. Illegal drugs are prohibited.</p>

<h3>Photography and privacy</h3>
<p>Events take place in public-facing venues where photos and video may be taken, and the organizers can't guarantee you won't be photographed. If staying anonymous matters to you, take your own precautions, such as a mask or hood. Please ask before taking close-up photos of others, and never deliberately identify or "out" another attendee.</p>

<h3>Assumption of risk and release</h3>
<p>I understand that moshing is a voluntary, physical activity with risks that can't be removed entirely, including bumps, bruises, scrapes, strains, sprains, falls, being stepped on, lost or damaged belongings, and other injury, some of which may be serious.</p>
<p>I understand that:</p>
<ul>
<li>My decision to enter the pit is my own, and I may leave it at any time.</li>
<li>I am responsible for knowing my own physical limits and any condition that could make moshing unsafe for me.</li>
<li>Monitors help keep the pit safe but cannot watch everyone or prevent every injury.</li>
<li>Central Ohio Leather &amp; Beyond, the event hosts, volunteers and monitors, and the venue (the "Released Parties") do not guarantee that the pit is free of risk.</li>
</ul>
<p>If I choose to enter the pit, I knowingly accept and assume the inherent and reasonably foreseeable risks of moshing. To the fullest extent permitted by Ohio law, I release the Released Parties from claims arising from ordinary negligence connected with my voluntary participation.</p>
<p>Nothing in this agreement waives claims or liability that cannot lawfully be waived, including for intentional misconduct, willful or wanton misconduct, nonconsensual conduct or anything else Ohio law does not allow a participant to release.</p>

<h3>Reporting concerns</h3>
<p>Tell a monitor, an event host or venue staff. Reports are handled as discreetly as reasonably possible, but confidentiality can't be guaranteed when a safety concern, venue issue or legal duty requires sharing. Medical emergencies should be reported to venue staff right away. Event organizers are not a substitute for emergency services, law enforcement or medical care.</p>

<h3>Enforcement</h3>
<p>Hosts, monitors and venue management may step in when these rules are broken. Responses can include a reminder, a warning, being asked to leave the pit or the event, being barred from future events, or contacting emergency services or law enforcement. Violence, threats, harassment or nonconsensual contact may lead to immediate removal without warning.</p>

<p><strong>Consent. Respect. Safety. Community.</strong></p>
HTML
	,
);
