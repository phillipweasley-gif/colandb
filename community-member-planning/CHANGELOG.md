# Changelog — Community Member Planning

Private member area for the Community Events Calendar site (project brief Phases 2–3). Versions follow semver; 0.x releases are the Phase 2 increments from `docs/phase-2-integration-plan.md` (repo root), and 1.0.0 will be Phase 2 complete. Every release: bump `CMP_VERSION` (header + `define`), add an entry here, and bump `CMP_DB_VERSION` if `CMP_Install::schema()` changed.

---

## 0.17.3

**One My calendar link, in the right places** (owner, 2026-10-05: "This all seems very redundant").

- **The site's account menu ("Hello, …") has My Calendar**, after My Profile, so it's one click from every page.
- **The events plugin's signed-in box now gets only My calendar** from this plugin. Member area is already in the menu, and accounts not yet set up see "Finish joining the member area" there, so the box shows nothing for them. Needs Community Events Calendar 1.31.1.
- **Verification:** `calendar-e2e.sh` 45/45, with a new check for the menu item; `profile-e2e.sh` 84/84.

---

## 0.17.2

**My calendar in the events plugin's quick links** (needs Community Events Calendar 1.31.0). When you're signed in, the login box on event pages such as Community Events shows **My calendar** and **Member area** first. An account that hasn't finished signing up gets "Finish setting up your account" instead.

- **Verification:** `calendar-e2e.sh` 44/44.

---

## 0.17.1

**"Browse events" on My calendar went to the home page** (owner, 2026-10-05). It picked the first page showing any calendar shortcode, and the home page shows a few upcoming events. It now prefers a page with the full calendar, then the event list, then an upcoming list, and never the home page. On colandb.com that's Community Events. The `cmp_events_url` filter can still set it.

- **Verification:** `calendar-e2e.sh` 40/40, with a new check using a home page with `[cec_upcoming]` and a calendar page.

---

## 0.17.0

**My calendar** (owner, 2026-10-05: "I don't have a way to 'Add to my calendar' or have a public, friends/dynamic, or private calendar for my profile. That was the whole goal of merging the two features."). Owner's choices:
- one calendar per member, with an audience per event;
- audiences: all signed-in members, dynamic partners (an active dynamic) or only me;
- "Going" counts as the RSVP;
- the profile's "Going to" becomes a Calendar tab, filtered by who's looking.

Needs Community Events Calendar 1.30.0 for the event-page box and RSVP sync. Without it, the tab and profile still work.

- **"Add to my calendar" on every event page**, under the Google Calendar and .ics links:
  - Going or Interested, and who can see it on your profile; tappable pills, starting from your default;
  - after saving: "On your calendar · Going · visible to …", with Change and Remove;
  - signed-out visitors get a sign-in link; accounts not yet set up are pointed to the member area.
- **Going = RSVP** on events that take RSVPs here:
  - the event's capacity applies, and a full event offers Interested;
  - Interested or Remove takes the RSVP away;
  - an RSVP made with the event's own form while signed in lands on your calendar as Going.
- **Calendar tab in the member area:**
  - upcoming events grouped by month, with who can see each (changing it saves straight away) and Remove;
  - recent past events;
  - the default for new events ("Dynamic partners" unless you change it);
  - a link to browse events.
- **Profile → Calendar tab** (was "Going to"): each viewer sees only what they're allowed to. Nothing hints that hidden events exist. Your own "as members see it" view shows only all-members events.
- **One rule for who sees what (`CMP_Calendar::can_see`):** you always; otherwise a signed-in member, not blocked either way, who is in the audience. "Dynamic partners" ends the moment the dynamic does.
- **Followers:**
  - "X is going to Y" goes only to followers allowed to see that event;
  - widening an event's audience tells only the newly included;
  - the "N people you follow are going" calendar marker counts only what the viewer may see;
  - the old "Show events I'm going to, to my followers" switch is replaced by a pointer to My calendar.
- **Upgrade (database version 13), run once:**
  - adds the `calendar` table;
  - RSVPs members made while signed in become Going: at All members for those who had the followers switch on (anyone could follow them), Only me for everyone else, so nothing becomes visible that wasn't before;
  - those who had it on keep All members as their default.
- **Retention:** calendar entries go 12 months after the event, like RSVPs (`cmp_retention_periods` key `calendar`). Export lists entries and your default, and erasing an account removes them.
- **Verification:** new `calendar-e2e.sh` 39/39 (event page states, Going/RSVP, capacity, Interested, Remove, validation, every audience, blocks, ended dynamics, notifications, widening, the marker, the tab, the default, form RSVPs, the upgrade, retention, export and erase). All other suites pass.

---

## 0.16.0

**Dynamics: several types at once, and chastity and homework as add-ons with any dynamic** (owner, 2026-10-05: "I should be able to select multiple. I should also be able to have the chastity option regardless of what the dynamic is providing the other party agrees to it. Same with Homework."). Owner's choices: the proposer picks who leads each add-on; add-ons can be added later with agreement; Keyholder / chastity wearer keeps chastity on automatically; existing dynamics keep only what they use.

- **Several types in one invitation.**
  - Types are tappable chips; each type with sides asks for your side once it's ticked.
  - The other member accepts or declines the whole invitation at once. It counts as one invitation in the tab badge and in the 10-pending limit.
  - Each type can still be ended on its own.
- **Chastity and homework are add-ons.**
  - They work with any type, including equal ones such as Partners.
  - Each has its own direction: "I hold the key / They hold the key", "I set it / They set it".
  - They're asked for in the invitation and start only when it's accepted.
- **On an active dynamic:**
  - **Ask to add** chastity or homework, either direction. The other member accepts or declines, and the asker can withdraw.
  - **Turn off** any add-on: either member, at any time, straight away. The other member is told.
  - **Add a type** links to the proposal form on their profile.
- **Keyholder / chastity wearer** always comes with chastity, the Keyholder holding the key. The form ticks it and locks the direction.
- **What homework and chastity check now:** only an agreed add-on, while at least one dynamic between the two is active.
  - Being the "leading side" no longer gives anything by itself.
  - When the last dynamic ends, add-ons end with it.
  - Blocking ends them too.
  - Homework is archived and locks become self-locks, as before.
- **Upgrade (database version 12), run once:**
  - Adds the `dynamic_addons` table and `dynamics.group_id`.
  - Each active pair keeps only what it uses: chastity for Keyholder dynamics or a lock in place, homework if a program is running.
  - Pending Keyholder invitations get their chastity request.
- Homework and Chastity tab wording follows the new rules. The data export lists add-ons, and erasing an account removes them.
- **Verification:**
  - `dynamics-e2e.sh` 56/56, with 22 new checks: several types and add-ons in one invitation, sides required, nothing allowed while pending, accepting starts everything, directions respected, turn off, ask again, duplicate and self-accept refused, no asking without a dynamic, ending one type versus the last, the Keyholder rule, withdrawing, and the upgrade.
  - Homework and chastity tests now create their add-on explicitly.
  - All 15 suites pass.
  - In a browser on the local test site: the chips, side questions, add-on rows, the Keyholder lock-in, sending an invitation, and the Dynamics tab with an active pair, an add-on request and Ask to add.

---

## 0.15.2

**"Leave site?" when sending a message or posting to the feed, and the dynamic proposal layout** (owner, 2026-10-05: "it shouldn't have the 'leave' that's confusing for users"; "I get the same leave message when I post to the feed as well"; screenshot of "Your side" with stretched buttons).

- **The real cause of "Leave site?" was Elementor's AI assistant (Angie), not our forms.** It loads for administrators on every front-end page inside a frame from Elementor's own site, and that frame makes the browser ask "Leave site?" on every link and form. A page can't switch off another site's frame, so 0.15.1's guard couldn't help. Taking the frame off the page made links and Send work straight away. Members who aren't administrators never had Angie, so they weren't affected. Now:
  - on the member page (feed, messages, profiles and the rest), Angie's scripts and styles aren't loaded at all;
  - Angie still works everywhere else, including the Elementor editor;
  - this also keeps an AI tool off private member pages, as the privacy statement promises;
  - the `cmp_leave_out_handle` filter can change which scripts are left out.
- **Propose a dynamic → "Your side":** the text-box rule (full width, 44px tall) also applied to the two radio buttons, stretching them across the form and pushing their labels outside. That rule now applies only to text boxes. Each side is a tappable row, and the whole row selects it.
- **"Message (optional)" boxes** are dark like the other fields, not bright white.
- **Verification:**
  - `messages-e2e.sh` 51/51, with new checks: Angie's scripts, inline setup and styles are left out of the member page; other scripts still load; Angie still loads on other pages; and the radio fix.
  - All other suites pass.
  - In a browser on the local test site, the proposal form showed normal radio rows (the whole row taps), with dark message boxes.
  - On the live site, removing Angie's frame let links and Send work without the pop-up.

---

## 0.15.1

**Messages and nods fixes** (owner, 2026-10-05: sending a message "just asks if I want to leave", the message wasn't received, "no way for me to see who I nodded to or the messages I sent", and "nods should be limited to once per receiver per 24 hours").

- **Sending works even when other scripts ask "Leave site?"** On the live site, Elementor and the push service add that warning to pages with forms. If someone chose to stay, our double-submit guard had already locked the form, so pressing Send again silently did nothing and the message was never saved. Now:
  - while one of our forms is submitting, a capturing `beforeunload` listener stops other scripts' warnings (only during our own submit);
  - a form unlocks itself after 6 seconds if the page didn't leave;
  - forms also unlock when you come back with the Back button.
- **Messages → Sent:** your requests to members you're not connected with, "waiting for them to accept", in their own list instead of mixed into the Inbox. The conversation's back link returns there.
- **Nods once per member every 24 hours.** Within 24 hours a second nod is refused ("You've already nodded at them today"). After 24 hours the profile button says **Nod again**, and nodding again tells them again.
- **Messages → Nods** now has two lists: **Nodded at you** and **You nodded at** (when, whether they nodded back, Nod again once allowed, Message).
- **Verification:** `nods-e2e.sh` 23/23 (new: the 24-hour rule, Nod again, You nodded at) and `messages-e2e.sh` 47/47 (new: Sent, not in Inbox). All other suites pass. In a browser, a message was sent with a "Leave site?" handler added to the page, the same situation as on the live site.

---

## 0.15.0

**Automatic data clean-up** (owner, 2026-10-05: "Yes"; it matches the retention table in `docs/privacy-statement.md`). New `CMP_Retention` runs once a day (WP-Cron `cmp_daily_retention`, cleared on deactivation):

- **Audit log:** entries older than 2 years are deleted.
- **Member reports:** deleted 3 years after they were made.
- **Accounts never verified:** deleted 60 days after sign-up. On purpose, this only applies to accounts that:
  - are plain subscribers;
  - were created after this version was installed (the `cmp_retention_since` option), so older accounts are never swept up;
  - have no posts or events of any kind and no RSVPs.
  Each removal is audit-logged.
- **Deletion requests:** administrators see a dashboard notice when a confirmed data deletion request has been waiting 20 days, since the statement promises 30.
- Periods can be changed with the `cmp_retention_periods` filter (days). The last run's counts are stored in the `cmp_retention_last` option.
- Verification: new `tests/retention-e2e.sh` 17/17; all other suites pass (setup 39, profile 84, dob 34, account 69, feed 41, messages 46, follows 27, directory 21, nods 20, dynamics 34, homework 44, chastity 55, e2e 60).

---

## 0.14.0

**Nods: a quiet way to show interest** (owner, 2026-10-05: "a way to indicate interest passively … somewhat neutral … related to kink but not aggressive", not another app's version). Named after the nod across the bar; our own word and our own mark (a small dot with a dip beneath), not another app's feature name or icon.

- **`CMP_Nods`**, new table `cmp_nods` (`CMP_DB_VERSION` 11). A **Nod** button in every profile header (beside Follow); tap again to take it back. One nod per member per member; at most 30 a day.
- **Messages → Nods** (badge for new ones, also counted in "Messages (N)"): who nodded at you, with **Nod back** and **Message**. Ignoring a nod tells nobody anything.
- **Mutual nods:** both members are told "You and <name> both nodded. Say hello?" with a link to message, and from then on messages between them go straight to the inbox (they count as connected, like an active dynamic).
- **Wording never assumes pronouns** (owner): every nod message uses names only.
- New notification category **Nods** (can be turned off). Blocks remove nods both ways and stop new ones. Export lists nods you sent; erase removes nods both ways.
- **Verification** (local WordPress 7.1.2): new `tests/nods-e2e.sh` 20/20 (incl. a check that no nod wording contains he / she / him / his / her); all other suites pass (setup 39, profile 84, dob 34, account 69, feed 41, messages 46, follows 27, directory 21, dynamics 34, homework 44, chastity 55, e2e 60). In a browser at 1440 px: the Nod button in a profile header and the Nods list.

---

## 0.13.1

**Wider member area on desktop** (owner, 2026-10-05: "why is it so thin on desktop?"). The member area was capped at 760px (sized for forms in 0.1). It's now up to 1200px, so profiles (Details beside About, buttons beside the name), Members (filters beside a 3-column grid) and messages use the screen. The Profile, Account and Setup tabs and the Feed keep a reading width of 860px for their content; the tab bar stays full width on every tab so it doesn't jump.

- Verification: all suites pass (setup 39, profile 84, dob 34, account 69, feed 41, messages 46, follows 27, directory 21, dynamics 34, homework 44, chastity 55, e2e 60); checked in a browser at 1440 px: a profile, Members, the Profile tab and the Feed.

---

## 0.13.0

**Facebook / FetLife-style profiles and member search** (owner, 2026-10-05: "Even though I have a cover image it doesn't show in my profile … We want a Facebook or FetLife style profile view. I also need a way to search for members or filter members … click on one of the items in their profile to get a list of members who have that item"). From `docs/mockups/profile-directory.html`, with the owner's answers: everyone listed with an opt-out, location by city / state for now, sections as tabs.

- **Cover image fixed.** 0.5.0's phone-first layout hid the cover (`.cmp-prof-cover { display: none }`). Profiles now show the cover across the top (3:1), with the profile photo overlapping it; no cover gives a plain band.
- **New profile layout:** name with the "54 M Dominant" tag, location · pronouns · expression, follower counts, and **Follow · Message · Block** in the header. Below, **tabs: About · Kinks · Going to · Posts** (only tabs with something in them; arrow keys move between tabs; without JavaScript every section shows in turn). About has a Details list beside About me, Looking for, Not looking for, Interests, Hard limits and Health. Propose a dynamic and Report sit under the profile.
- **Clickable items:** roles, orientation, gender, position, body type, how active, hosting, looking for, interests, kinks and the city link to Members filtered by that item, but only for answers shown to all members.
- **`CMP_Directory`: a Members tab** (after Feed). Filters: name, role, looking for, orientation, gender, position, body type, kinks, interests, how active, hosting, age range, city or state, with a profile photo, people I follow; sort by newest or name; 24 per page. Active filters show as chips you can remove. Result cards show the cover strip, photo, name, "age gender role · city" and two "Looking for" items.
- **Privacy:** only answers shown to all members are ever matched (an age filter only finds members who show their age). Full members only; never yourself, members who opted out, or anyone blocked either way. **"Hide me from member search"** on the Profile tab; export says whether you're hidden, erase removes the setting.
- **Fix:** member profile pages now load the member-area script (profile tabs, the Propose form's labels, confirmations).
- **Verification** (local WordPress 7.1.2): new `tests/directory-e2e.sh` 21/21; `setup-e2e.sh` 39/39 and `profile-e2e.sh` 84/84 (two checks updated for the new header), `dob-e2e.sh` 34/34, `dynamics-e2e.sh` 34/34, `homework-e2e.sh` 44/44, `chastity-e2e.sh` 55/55, `feed-e2e.sh` 41/41, `account-e2e.sh` 69/69, `messages-e2e.sh` 46/46, `follows-e2e.sh` 27/27, `e2e.sh` 60/60. In a browser at 1280 px and 375 px: the cover and photo, tabs, clicking "Keyholder" on a profile into a filtered Members list, and the Members grid.

---

## 0.12.1

**Signing in lands in the member area** (needs Community Events Calendar 1.28.1). Members who sign in with the site's Log In link (or `wp-login.php`) with no particular destination now go to their member area instead of Submit an Event. Administrators and event managers keep their usual destination; a requested destination (`redirect_to`) is still respected. Uses the new `cec_login_default_redirect` filter.

- Verification: `account-e2e.sh` 69/69, `dob-e2e.sh` 34/34, `setup-e2e.sh` 39/39, `e2e.sh` 60/60 (local WordPress 7.1.2).

---

## 0.12.0

**Follow / unfollow** (owner, 2026-10-05: "in case you don't want to be friends but want to engage and see where someone is attending"). Owner's choices: anyone can follow, no approval; event plans only if the member opts in; shown as a Following feed filter, a "Going to" profile card, a calendar marker and notifications. Needs Community Events Calendar 1.28.0 for the calendar marker and RSVP notifications.

- **`CMP_Follows`**, new table `cmp_follows` (`CMP_DB_VERSION` 10).
- **Follow / Following** button on member profiles, with "N followers · N following" (also on your own profile view and the Profile tab).
- **"Events I'm going to"** (Profile tab), **off by default**: when on, your followers see a **Going to** card on your profile (your next 5 upcoming, published events you RSVP'd to while signed in, with each event's own time setting), get a notification when you RSVP, and see the calendar marker. Non-followers see "Follow <name> to see the events they're going to". Your own profile view marks the card "Only your followers see this".
- **Feed → Everyone / Following**: just the posts of members you follow (and can see).
- **Calendar:** "1 person you follow is going" / "N people you follow are going" via `cec_event_social_label`: a dot on desktop, a line on the phone list.
- **Notifications** (new category "People I follow", can be turned off): a followed member's new post (only to followers who can see it) and, if they share their events, their RSVPs.
- **Blocks** remove follows both ways and stop new ones; nothing crosses a block.
- **Privacy:** export lists who you follow and your sharing choice; erase removes follows both ways and the setting.
- **Verification** (local WordPress 7.1.2): new `tests/follows-e2e.sh` 27/27; `setup-e2e.sh` 39/39, `profile-e2e.sh` 84/84, `dob-e2e.sh` 34/34, `dynamics-e2e.sh` 34/34, `homework-e2e.sh` 44/44, `chastity-e2e.sh` 55/55, `feed-e2e.sh` 41/41, `account-e2e.sh` 64/64, `messages-e2e.sh` 46/46, `e2e.sh` 60/60. In a browser: profile button and Going to card (375 px), the Following filter (moved into the Feed header card after it rendered on the page background), and the calendar marker at 1280 px and 375 px.

---

## 0.11.0

**Member messages, with block and report** (owner, 2026-10-05: "a way for members to send messages to one another … as well as block or report"). From `docs/mockups/messages.html`, with the owner's answers: anyone can write, as requests; text only for now; reports show the whole conversation.

- **`CMP_Messages`**, four new tables `cmp_conversations`, `cmp_messages`, `cmp_blocks`, `cmp_message_reports` (`CMP_DB_VERSION` 9), and a **Messages** tab right after Feed, with an unread count ("Messages (2)").
- **Writing:** "Message" on any member's profile. One conversation per pair of members; text up to 2,000 characters; Ctrl/⌘+Enter sends.
- **Requests:** between members who aren't connected (no active dynamic) the first message is a request. It waits under **Requests** (with a count) until accepted; replying accepts it too. The sender can send at most 3 messages until then. Connected members go straight to the inbox. Members can turn requests off ("Accept message requests from members I'm not connected with"); their profile then says "Not accepting message requests". At most 20 new conversations a day per member. New requests send an in-site notification (new category "New message requests", can be turned off).
- **Read state** is tracked by message, so unread dots and counts are exact even when messages share a second.
- **Delete** a conversation from your side; the other member keeps theirs. A later message brings it back with only the new messages.
- **Block** (from a conversation, a request or a profile): they can't message you or start a conversation, neither of you can see the other's profile or feed posts, the conversation disappears for both, dynamic proposals are refused, and any dynamic between you ends (which archives homework and turns a lock into a self-lock). They aren't told. **Messages → Blocked** lists who you blocked, with Unblock.
- **Report** (from a conversation or a profile): a reason (harassment or threats, unwanted sexual content, spam or scam, underage or not consenting, something else), an optional note, and "Also block them" (on by default). The site admin email gets a link, never the messages themselves. Administrators review on **Users → Member reports** ("Member reports (N)" while any are open): who, why, and the whole conversation; opening one is audit-logged; "Mark as reviewed" closes it. Otherwise nobody but the two members can read a conversation.
- **Privacy:** export lists the messages you sent and who you blocked; erase removes the member's conversations (both sides), blocks both ways and the reports they made; reports about them are kept.
- **Verification** (local WordPress 7.1.2): new `tests/messages-e2e.sh` 46/46; `setup-e2e.sh` 39/39, `profile-e2e.sh` 84/84, `dob-e2e.sh` 34/34, `dynamics-e2e.sh` 34/34 (its nonce helper now picks the dynamics form, since profiles carry more forms), `homework-e2e.sh` 44/44, `chastity-e2e.sh` 55/55, `feed-e2e.sh` 41/41, `account-e2e.sh` 64/64, `e2e.sh` 60/60. In a browser at 375 px: inbox, conversation and its menu, sending, a request, and profile buttons.

---

## 0.10.0

**Kink picker** (owner, 2026-10-05: the kinks list "becomes cumbersome"; approved from `docs/mockups/kink-picker.html`).

- **Only your picks are listed** on the Profile tab and setup step, each with Love it / Like it / Curious and Giving / Receiving / Both as tap-sized buttons (tap the chosen Giving / Receiving / Both again to clear it) and a × to remove it. A "N of 40 picked" count.
- **Add kinks** by typing (Enter adds the first match) or tapping a category; results are tap targets. Without JavaScript, "Browse all kinks" lists every kink by category with the same buttons, and saves the same way.
- **Categories** (11): Bondage & restraint · Impact · Chastity & control · Service & protocol · Pup & pet · Role & age play · Fetish & gear · Sensation · Body worship · Exhibition & voyeur · Other. The site team sets each kink's category on Users → Member Profile Options → Kinks (new Category column).
- **62 starter kinks** (the 30 from 0.5.0 plus 32 more). A one-time upgrade (`CMP_DB_VERSION` 8) gives existing kinks a category and adds the new ones; kinks the site team renamed or retired, and ones they added, are left as they are.
- **On profiles** kinks are grouped under Love it / Like it / Curious, with giving / receiving beside each.

**Fixes**

- **Show switches came back.** 0.9.1 accidentally removed the script that reveals a field's "Show to members" switch once it's filled in, so a newly filled field couldn't be switched off until after saving. Restored. A picker's empty radios don't count as "filled".
- **Single-choice fields start blank.** Body type, position, hosting and "how active" had no blank choice, so any Profile tab save quietly set them to their first option ("Slim", "Top", …). They now start on "Not set". Members who saved their profile since 0.5.0 may show a body type or position they never chose; they can set it back to "Not set".

**Verification** (local WordPress 7.1.2): `setup-e2e.sh` 39/39 (new: categories, upgrade, admin categories, picker markup, removing), `profile-e2e.sh` 84/84, `dob-e2e.sh` 34/34, `dynamics-e2e.sh` 34/34, `homework-e2e.sh` 44/44, `chastity-e2e.sh` 55/55, `feed-e2e.sh` 41/41, `account-e2e.sh` 64/64, `e2e.sh` 60/60. In a browser at 375 px: search, Enter to add, categories, levels, clearing a direction, removing, saving, the Show switch appearing and hiding, and the grouped profile view.

---

## 0.9.1

**Profile fixes from the owner's review of the live site (2026-10-05).**

- **Photo descriptions are optional.** No more "Describe the photo or tick Decorative image" error. The description sits behind "Add a description (optional)"; without one, a profile photo is read by screen readers as "Profile photo of <display name>" and a cover image is treated as decorative.
- **Profile photo and cover image can be uploaded together.** Saving one photo used to reload the page at once, dropping a photo already chosen (and cropped) in the other panel. Now one Save uploads every photo that's waiting, then reloads once; if one fails, the others stay saved and the failed one says why.
- **The display name can't be hidden.** It has no Show switch ("Always shown") and always counts as shown to members, including for existing members who had switched it off. It's already how members appear on posts, dynamics and homework.
- **Orientation is a fixed list** (no "Other, in your own words"), so members can be found by it. Earlier free-text answers are kept in the database but no longer shown.
- **"View my profile as members see it"** button on the Profile tab: opens your own member link (`?cmp_member=<your ID>`) showing exactly what other members see, your all-members posts, and an "Edit my profile" link. Author names in the feed now link to member profiles (yours included).
- **Tests:** `profile-e2e.sh` makes its test photos with PHP's GD instead of ImageMagick, so all 83 checks now run on a Mac (the 12 photo checks used to be skipped); new checks for the display name, orientation, own-profile view and photo alt text. `dob-e2e.sh` updated for the always-shown display name. Also checked in a browser: choosing both photos and pressing Save on one stores both (512×512 and 1500×500) with no description.
- **Verification** (local WordPress 7.1.2): `profile-e2e.sh` 83/83, `dob-e2e.sh` 34/34, `setup-e2e.sh` 32/32, `feed-e2e.sh` 41/41, `account-e2e.sh` 64/64, `e2e.sh` 60/60.

---

## 0.9.0

**Member feed** (owner, 2026-10-04: "there should also be an activity feed for members and a way for them to share text and image posts (like tagging an event for photos they took there etc.)"). Built overnight from `docs/mockups/feed.html`; **not yet approved by the owner.**

- **`CMP_Feed`**, three new tables `cmp_posts`, `cmp_post_photos`, `cmp_post_likes` (`CMP_DB_VERSION` 7), and a **Feed** tab right after Home.
- **Posts:** text up to 2,000 characters and/or up to 4 photos (JPEG/PNG/WebP under 5 MB, re-encoded to JPEG at most 1600 px, location data removed). Optional **event tag**: a published Community Events Calendar event from the last 60 days or the next 30.
- **Audience:** "All members" or "My connections" (members in an active dynamic with the author). Members only: signed-out visitors and accounts that aren't full members see nothing, and photos 404 for anyone who can't see the post. If the dynamic ends, connections-only posts disappear for that person at once.
- **Event filter:** the tag links to the feed filtered to that event (every member's posts and photos from it), with a link to the event page.
- **Likes** (♥ toggle). No comments yet.
- **Moderation:** any member can report a post once (reason optional, audit-logged); the site admin email gets a message with a link; administrators see "Hide (admin) · N reports" and can hide any post (the author still sees it, marked hidden); authors delete their own posts with their photos and likes.
- **Profiles:** a member's profile shows their 5 most recent posts that the viewer may see.
- **Privacy:** export lists the member's posts; erase deletes their posts, photos and likes.
- **Verification** (local WordPress 7.1.2):
  - new `tests/feed-e2e.sh` 41/41;
  - `chastity-e2e.sh` 55/55, `homework-e2e.sh` 44/44, `dynamics-e2e.sh` 34/34, `setup-e2e.sh` 32/32, `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick); no PHP warnings;
  - rendered at 375 px and 1280 px under Hello Elementor; fixed avatar circles and author links that the member area's own link rule was overriding.

---

## 0.8.0

**Chastity tracking** (owner, 2026-10-04: "For tracking chastity we can use Chaster as an example"; the tracker's chastity options). Built overnight from `docs/mockups/chastity.html`; **not yet approved by the owner.**

- **`CMP_Chastity`**, two new tables `cmp_locks`, `cmp_lock_events` (`CMP_DB_VERSION` 6), a **Chastity** tab, and a `lock` notification category ("Chastity lock updates", members can turn it off).
- **Starting a lock (the wearer):** self-lock, or with a keyholder who leads them in an active directed dynamic (`CMP_Dynamics`). One active lock at a time. Options from the tracker: Full lockdown · One release a week (with permission) · Daily routine, no release · Custom (name, rule, release policy: none / with permission / allowed). Planned length 1 hour – 30 days or open-ended; optional photo.
- **Keyholder:** add or remove time (never below now), hide the time left, change the rules, daily verification on/off, hygiene allowance (none / 10 / 15 / 30 / 60 minutes), allow one release, review verification photos (accept / ask for another), unlock.
- **Wearer:** live "Locked for" timer; time left unless hidden (then neither the end date, time changes, nor the planned length are shown to them); verification photo with **today's code** (6 characters, different per lock and per day, keyed with the site salt), to write on paper in the photo, like Chaster; hygiene opening and relock (flagged when longer than allowed); log a release (recorded either way, flagged when the rule didn't allow it); ask to be unlocked; self-locks unlock once their time is up.
- **Safety:** "Emergency unlock" is always available to the wearer, ends the lock at once, is recorded, and tells the keyholder. The page says plainly that this tracks a lock and doesn't control a device.
- **Dynamic ends:** the keyholder loses access at once (controls and photos); the lock carries on as a self-lock, timer shown again, wearer told.
- **Photos:** re-encoded and resized like homework proof (no location data), stored privately, served only to the wearer and their current keyholder (`?cmp_lockpic=<id>`, 404 for anyone else).
- **Calendar:** this month's locked days and verified days (Chaster's activity calendar). Past locks listed with length and how they ended.
- **Profile:** a "🔒 Locked · N days" badge, only if the wearer turns it on (off by default).
- **Privacy:** export lists locks (as wearer or keyholder) and the wearer's full history; erase deletes the wearer's locks and history, and turns locks they held as keyholder into self-locks.
- **Verification** (local WordPress 7.1.2):
  - new `tests/chastity-e2e.sh` 55/55;
  - `homework-e2e.sh` 44/44, `dynamics-e2e.sh` 34/34, `setup-e2e.sh` 32/32, `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick); no plugin PHP warnings;
  - rendered at 375 px under Hello Elementor (wearer and keyholder views).

---

## 0.7.0

**Homework programs** (owner, 2026-10-04: the Weekly Homework Tracker spreadsheet is "a great example of how a Dom might manage their submissive"; the core features should "enhance the tasks and calendar" and let members "manage their dynamics"). Built overnight from `docs/mockups/homework.html`; **not yet approved by the owner.**

- **`CMP_Homework`**, three new tables `cmp_programs`, `cmp_tasks`, `cmp_task_entries` (`CMP_DB_VERSION` 5).
- **Who can set homework:** only the leading side of an active directed dynamic (`CMP_Dynamics::lead_can_direct()`). When the dynamic ends, its programs are archived at once: no more entries, the leading side loses access to proof photos immediately, and the member keeps their history read-only.
- **A program** = title, notes to the member, and a consequence ladder (up to 8 rows: level, examples, correction), modelled on the tracker's "Consequences" sheet.
- **Tasks** (up to 30 per program), modelled on the tracker's task list:
  - category: daily ritual, body / grooming, domestic service, education, attention / check-in, reflection, chastity, fitness, other;
  - weekly minimum (1–7), "what counts", an optional standard phrase (e.g. "Good morning, Sir.");
  - proof: none, text, photo, photo + text, written report.
- **Logging (member):** done / moved / not applicable for today or the last 7 days, never future days; note and proof photo as the task asks. Photos are resized to ≤1600 px and re-encoded as JPEG, which strips EXIF/GPS; stored privately in the database and served only to the two members of the program (`?cmp_proof=<id>`, 404 for anyone else).
- **Review (lead):** accept, "not good enough" (doesn't count toward the quota), or mark a day missed; optional note back. Notifications for new assignments, submissions, and review decisions.
- **Week view:** Monday-start week with Mon–Sun cells per task, "quotas met", an overall status (On track / Needs work), entries waiting for review, and a month heat map.
- **Homework tab** in the member area: "Your homework" and "Homework you set". Export lists a member's programs, tasks and entries (without photo bytes); erase deletes them (before dynamics).
- **Index names:** MySQL 8 reserves `LEAD` and `MEMBER`, so every index added in 0.6.0 / 0.7.0 is now named `idx_*` (`idx_proposer`, `idx_partner` on `cmp_dynamics`). 0.6.0 has not shipped, so no live table has the old names.
- **Verification** (local WordPress 7.1.2):
  - new `tests/homework-e2e.sh` 44/44 (incl. EXIF marker stripped from proof photos, proof photo 404 for a third member, archive on dynamic end);
  - `dynamics-e2e.sh` 34/34, `setup-e2e.sh` 32/32, `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick); no PHP warnings;
  - rendered at 375 px and 1280 px under Hello Elementor.

---

## 0.6.0

**Dynamics between members** (owner, 2026-10-04: FetLife's relationships and power dynamics "will be very important for the homework and chastity tasks"). Built overnight from `docs/mockups/dynamics.html`; **not yet approved by the owner.**

- **`CMP_Dynamics`**, a new table `cmp_dynamics` (`CMP_DB_VERSION` 4). A dynamic is a type between a proposer and a partner:
  - 12 directed types, each a pair of sides: Keyholder / chastity wearer · Dominant / submissive · Daddy / boy · Daddy / girl · Mommy / boy · Owner / property · Master / slave · Handler / pup · Trainer / trainee · Mentor / mentee · Top / bottom · Caregiver / little;
  - 5 equal types: Partners · Married · Play partners · Leather family · Friends.
- **Consent rules:**
  - nothing happens until the partner accepts (pending → active);
  - either member can end an active dynamic alone, at any time, and it takes effect immediately (`cmp_dynamic_ended` action);
  - the proposer can withdraw a pending invitation;
  - only the partner can accept or decline.
- **Who may direct whom:** `CMP_Dynamics::lead_can_direct( $lead, $member, $type = null )` is true only for the leading side of an active directed dynamic. Releases 0.7.0 (homework) and 0.8.0 (chastity) build on it. Equal types give nobody that.
- **Connections:** members in an active dynamic count as connected (`cmp_are_connected`), so "My connections" visibility now works.
- **Screens:**
  - a new **Dynamics** tab ("Dynamics (N)" when invitations are waiting) with Waiting for you / Active / Sent by you;
  - "Propose a dynamic" on member profiles (type, your side, optional message up to 500 characters);
  - a Dynamics card on profiles, shown only while active and only if both members leave "Show on my profile" on.
- **Limits:** at most 10 pending invitations sent at once; no second pending or active dynamic of the same type between the same two members; members only; not to yourself; nonce-checked; same "no longer available" answer for anything not yours.
- **Notifications** (invitation; accepted / declined / ended) and an audit trail (proposed, accepted, declined, withdrawn, ended). Export lists a member's dynamics; erase deletes them.
- **Verification** (local WordPress 7.1.2):
  - new `tests/dynamics-e2e.sh` 34/34;
  - `setup-e2e.sh` 32/32, `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick);
  - one profile check now reads only the profile card, because "Leather family" is also a dynamic type in the Propose form on that page;
  - no PHP warnings;
  - checked on Hello Elementor at 375px: invitation, accept, active list and the profile Dynamics card.

## 0.5.0

**Profiles v2 and step-by-step profile setup** (owner requests 2026-10-04: profiles modeled on Sniffies for phones and FetLife for desktop, data points and "Looking for" from Sniffies / FetLife / Chaster, and "filling out a member profile in stages … with a bio" right after sign-up). Built overnight while the owner was away; the mockup is `docs/mockups/profiles-v2.html` and the research notes are linked from the PR. **Not yet approved by the owner.**

- **New fields**, grouped into sections (Basics, Identity, Stats, Scene, Kinks & limits, Health):
  - Basics: Hosting, How active (FetLife's scale).
  - Identity: Gender (up to 3, plus "other"), Position, Expression (own words). "Orientation / identity" is now labelled Orientation.
  - Stats: Height (list, stored in inches, shown 5'11"), Weight (lb).
  - Scene: Not looking for.
  - Kinks & limits: Kinks, rated Chaster-style (Love it / Like it / Curious, plus Giving / Receiving / Both); Hard limits.
  - Health: Safer-sex practices, Last tested (month), Substances.
  - Bio grows to 1,000 characters.
- **Starter lists** for every option list, trimmed from the research (e.g. 36 roles instead of FetLife's 813, 30 kinks, 16 "looking for"). `CMP_Profile_Fields::seed_defaults()` runs on upgrade (`CMP_DB_VERSION` 3) and only fills lists that have no options at all, so the site team's own lists are untouched.
- **Health starts hidden** even when filled in: the one exception to "a filled-in field starts shown" (`'sensitive'` in the dictionary, `data-cmp-sensitive` on the switch).
- **Left out on purpose** (easy to add if wanted): HIV status as its own field (practices + last tested cover it with less exposure), endowment.
- **Step-by-step setup** (`CMP_Onboarding`): six steps (Photo & bio · Basics · Identity & roles · Stats · Kinks & limits · Health).
  - Each step saves only its own fields through the normal profile save (`only[]`, `cmp_return`), so validation and visibility rules are shared. Errors return to the same step with answers kept.
  - "Skip for now", "Back", "Set up my profile later". "Skip" on the last step finishes.
  - A new member's home opens the steps and stays there until they finish or choose later, resuming where they left off. Members who already had something on their profile get a "Finish your profile" card instead.
  - The photo upload returns to the step (`cmp_return` on the photo form).
- **Profile display redesign** (`card_html()`), laid out by the profile's own width (CSS container queries):
  - narrow (phones), Sniffies-style: photo first, name with a FetLife-style "41 M Dominant" tag, an auto-built stat line (age · height · weight · body type · orientation · position · roles), the bio as a quote, then chip sections, kinks with level and direction, hard limits and health;
  - from 600px, FetLife-style: header band with a square photo and a details table (Gender, Pronouns, Orientation, Roles, Expression, Active, Looking for, Member since);
  - from 820px, a Stats column beside the About column.
- **Verification** in a local WordPress 7.1.2 (PHP 8.4, SQLite):
  - new `tests/setup-e2e.sh` 32/32 (starter lists, one-step saves, resume, rated kinks, height/weight/month validation, health hidden until switched on, finish/later/skip, nudge for existing members, display);
  - `profile-e2e.sh` 68 (+12 photo checks needing ImageMagick), `dob-e2e.sh` 34/34, `e2e.sh` 60/60, `account-e2e.sh` 64/64, no PHP warnings;
  - checked in the browser on Hello Elementor at 375px, 710px and 1215px wide.

## 0.4.1

**New accounts land in the member area after sign-up**, not the events plugin's Submit an Event page (owner report 2026-10-04, after registering on the live site). There the next steps are confirming the email address and then the profile. Uses CEC 1.27.2's `cec_register_redirect`, and only when a member page is set. The step-by-step profile setup the owner asked for comes in a later release, from a mockup.
- Verified in a local WordPress 7.1.2: `tests/dob-e2e.sh` adds "new account lands in the member area, not Submit an Event". `php -l` clean.

## 0.4.0

**Date of birth at sign-up, and one Show switch per profile field** (owner decisions, 2026-10-04, from an approved mockup). Both override the project brief, which said to store only an 18+ attestation ("not date of birth") and to start every field private.

- **Date of birth is required** (`CMP_Birth_Date`, user meta `cmp_birth_date`).
  - Sign-up: the events plugin's `[cec_register]` form gets Month / Day / Year lists through its new hooks (CEC 1.27.1). No date, an impossible date (30 Feb) or an age under 18 refuses the sign-up before the account exists. The events plugin stores nothing.
  - Existing members: the "Confirm you are 18 or older" checkbox step becomes "One more step: enter your date of birth", asked once. Accounts that only ticked the old box are sent back to it (`CMP_Access::has_attested()` now means "an 18+ date on file and not locked").
  - Under 18 from a signed-in account: the member area is **locked** (`cmp_age_blocked_at`) and says to contact the site team, so a different year can't simply be tried next. Nothing is stored except the lock.
  - Entered once: members can't change it. The site team can correct it, or unlock a locked account, on the wp-admin user screen (administrators only, nonce-checked, audited without the date).
  - Never shown to anyone, never searchable, never in the audit log. Included in "export my data"; "erase my data" removes it but keeps an under-18 lock.
- **Age is calculated** from the date of birth (site timezone) and changes on the member's birthday. The typed age / age range from 0.1–0.3 is gone; any stored typed age is cleared when the date is recorded, keeping its visibility choice.
- **Show switches replace "Who can see this"** on every field and photo.
  - Empty fields have no switch. Filling one in reveals its switch already on (member.js; without JavaScript the hidden switch still posts "on").
  - On = All members (signed-in, verified members only; never the public). Off = Only me. A "My connections" choice made before 0.4.0 is kept while the switch stays off.
  - A field that stays empty keeps its stored choice. New photos start shown.
- **Defaults:** a brand-new account's display name, age and member-since start shown (`set_new_member_defaults()`). Existing members' fields keep exactly what they had, so nothing becomes visible without their say.
- Copy updated: the Profile intro, the age help text, the welcome notification and the step names. The Settings "18+ attestation wording" text is no longer shown to members; its version number is still recorded.
- **Verification**, in a real local WordPress 7.1.2 (PHP 8.4, SQLite) via `tests/`:
  - new `tests/dob-e2e.sh` 33/33: sign-up refusals and success, turning 18 today, new-member defaults, existing member asked once, date not changeable from the member area, typed age cleared, under-18 lock (retry with an adult year stays locked, REST closed), wp-admin correction and unlock, a bad nonce, no-JS "filled field starts shown", switch off, photo switch on for a new photo, birth date never on the profile page, export and erase;
  - `e2e.sh` 59/59 (updated for the date step; adds missing and impossible dates and an audit check);
  - `account-e2e.sh` 64/64;
  - `profile-e2e.sh` 68 passed, with the 12 photo checks not runnable on this Mac (no ImageMagick `convert` to make test images; they failed the same way before this change);
  - `php -l` clean on every file.
  - Checked in the browser on the Hello Elementor theme at 375px: the date step, the profile switches (typing reveals a switch already on) and the sign-up form.
  - Known test-script limit, not a site bug: under Hello Elementor `e2e.sh`'s `restnonce()` picks up another script's nonce first, so its 5 REST checks fail there; they pass on the default theme.

## 0.3.0

**Phase 2 increment 2.1: member profiles**, plus site-wide sign-in controls the owner asked for on 2026-10-04.

- **Profile tab** (Home | Profile | Account), for full members.
  - Every field in the brief's profile field dictionary:
    - display name (edited on the Account tab);
    - about me (500 characters, with a live counter);
    - pronouns (40);
    - location as city, region and country only (80 each);
    - interests (up to 20), roles/capacities (10), orientation/identity (10, plus "Other" in their own words, 80) and looking for (10), all from admin-managed lists;
    - availability (defaults to "Not listed"; never worked out from sign-ins);
    - age, as either an exact 18–120 or one of the six ranges (never a date of birth, never changes by itself);
    - member since (month and year, automatic).
  - **Each item has its own "Who can see this"**: Only me, My connections or All members, and **everything starts as Only me**.
    - Only me means the owner alone; site administrators get no exception.
    - My connections behaves like Only me until connections arrive in 2.3, and the page says so.
    - Nothing is ever shown to signed-out visitors, search engines, public feeds or the public REST API.
  - Validation:
    - Errors are listed at the top with links to each field, and the fields are marked invalid.
    - Nothing is saved until everything is valid, and the member's entries are kept.
    - Unknown choices are ignored.
    - A retired choice a member already has is kept, but isn't offered to anyone new.
  - **"What other members see"** preview of the profile card.
  - Another member's profile opens at `?cmp_member=<ID>` and shows only what that viewer may see. A profile they may not see, or of an account that isn't a full member, looks exactly like one that doesn't exist. The member directory that links to profiles comes in 2.4.
  - "Include in member search" choices are stored per field (default off) but **not offered yet**: they only mean something once the directory exists (2.4), so until then nothing is searchable.
- **Profile photo and cover image**, stored in the database (owner decision, 2026-10-04), never in the media library.
  - Accepts JPEG, PNG, WebP or HEIC up to 5 MB. The browser checks type, size and minimum size (200 × 200; cover 600 × 200) before uploading.
  - Cropping happens in the browser: a square, or 3:1 for the cover. The member drags the photo, uses the arrow keys or zooms with a slider, and sees upload progress.
  - Without JavaScript, or for a HEIC the browser can't open, the server crops from the centre.
  - The server checks the file's actual content (not its name), then decodes and re-encodes every photo as a fixed-size JPEG (512 × 512; cover 1500 × 500). That removes camera data, including GPS, and comments.
  - HEIC is converted by Safari, or by the server if its image library can; otherwise the member is told what to do.
  - "Decorative image" checkbox, or a required description of up to 150 characters. Alt text is never invented.
  - Replace and Remove controls, and a "Who can see this" setting per photo.
  - Photos are served only through `?cmp_photo=<ID>-<avatar|cover>`, which applies the same visibility rule.
    - Anything the viewer may not see gets the same 404 as a missing photo.
    - Responses carry `private, no-cache` plus an ETag, so browsers re-check every time and hiding a photo takes effect at once. They also carry `nosniff` and `noindex`, and `no-store` on refusals.
- **Users → Member Profile Options**, for the new "Manage Member Fields" capability, which administrators get on upgrade (decision D3).
  - Edit the interests, roles/capacities, orientation/identity, availability and looking-for lists.
  - Rename, reorder, stop offering, or add several at once (one per line).
  - Each choice keeps a stable key; choices are never deleted, only retired.
  - Duplicate names, regardless of capitals, are refused.
  - Changes are audited.
- **Notifications and time zone** on the Account tab:
  - The member's own time zone (real place names, grouped by region), used for dates and quiet hours.
  - Per-category switches for notifications. Account and security notices always stay on.
- **Sign-in controls everywhere:**
  - **The site's menus now follow sign-in state** (staging owner report: no way to sign out). Once signed in, the menu link to the sign-in page becomes **Sign Out** and the "Create an Account" link disappears. The calendar managers' "Admin Login" link stays only for managers and administrators. This works with the existing Elementor menu unchanged, because items are recognised by where they link. Any other menu item can be limited with the CSS class `cmp-signed-in-only` or `cmp-signed-out-only`.
  - **New `[cmp_account_bar]` shortcode** for the top right of the header, as the owner asked.
    - Signed out, it shows Log In and Create an Account.
    - Signed in, it shows the profile photo or initial and "Hello, <first name>", with an unread-notification count.
    - Its menu has Member Area, My Profile, Account Settings, Calendar Admin (managers and administrators only), WordPress Dashboard (administrators only) and Sign Out.
    - It works with keyboard and touch without JavaScript, and Escape or clicking elsewhere closes it.
    - Later releases can add items (connections in 2.3) through the `cmp_account_bar_items` filter.
- **Privacy:** the personal-data export now includes the profile, with each item's visibility and photo details. Erasure removes profile values and photos.
- **Audit:**
  - Which profile fields changed, not what they now say, so the log never becomes a second copy of members' details.
  - Every visibility change (old → new).
  - Photo added, replaced or removed, with fingerprints.
  - Preference changes and option-list changes.
- **Database version 2:** new tables `cmp_profile_values` and `cmp_profile_images`, created automatically on update. Uninstall (when opted in) removes them, the option lists and the capability.
- **Verified** in the local WordPress 7.1.2 install:
  - New `tests/profile-e2e.sh`, 76/76. It covers the options admin and its permissions; validation and nothing-saved-on-error; visibility for owner, another member, a connection (through the 2.3 filter), a non-member, a signed-out visitor and an administrator; and photos (no description, fake image, over 5 MB, too small, EXIF/GPS and comment stripping, sizes and shape, the JSON upload path, serving headers, 304, 404s, hiding at once, removal, not in the media library).
  - It also covers preferences and delivery; export and erasure; menu items for signed-out visitors, members and administrators; and the account bar for every kind of visitor, including name escaping.
  - `tests/account-e2e.sh` 64/64, `tests/e2e.sh` 57/57, `tests/updater-e2e.sh` 46/46.
  - Browser at 375/1440 px: no horizontal scrolling, and the cropper works.

## 0.2.0

**Front-end account management: members never need the WordPress dashboard.** On the owner's request (staging, 2026-10-04), the account part of increment 2.1 ships ahead of profiles.

- **New Account tab** on the member area (`?cmp_tab=account`), with Home | Account tabs and a Sign out link. It's open to every signed-in account, including one that hasn't confirmed its email yet, so a mistyped address can be fixed before verifying.
  - **Your details:** first name, last name and display name (required; up to 80 characters each). The username is shown but can't be changed.
  - **Email address:** shows the current address and whether it's confirmed. A change needs the current password. A single-use link goes to the *new* address (256-bit token, only its hash stored, 24-hour expiry, one request per 5 minutes, cancellable), and the address changes only when the link is opened. Opening it also confirms the new address for the member area, so members aren't asked again. WordPress emails the old address about the change. An address another account uses is refused.
  - **Password:** needs the current password; at least 10 characters, not the username or email, typed twice, with a "Show passwords" option. Changing it signs out every other device and keeps this browser signed in, and WordPress emails a confirmation.
  - **Signed-in devices:** shows how many other devices are signed in, with "Sign out everywhere else" and "Sign out".
  - **Your data:** "Email me a copy of my data" and "Ask to delete my account" use WordPress's own privacy requests. The person confirms by email, then an administrator completes the request under Tools → Export / Erase Personal Data.
  - Wrong current passwords are limited to 5 per 15 minutes per account, across the email and password forms.
- **Members are kept out of wp-admin** (new setting under Settings → Member Planning, on by default; needs the member page to be set). Accounts that can't write posts, manage calendar events or administer the site are sent from `wp-admin/profile.php`, or a sign-in aimed at it, to the Account tab, and WordPress's "Edit profile" links point there too. The rest of wp-admin was already closed to them by the events plugin (and by WordPress, which answers other screens with "not allowed"); without the events plugin, this plugin sends them from any wp-admin screen to the member area and hides the admin bar. Editors, calendar managers and administrators are unaffected. Form posts and background requests (`admin-post.php`, `admin-ajax.php`) keep working.
- **The "Wrong address?" link** on the confirm-email step now opens the Account tab instead of wp-admin's profile screen.
- **Privacy exporter and eraser** registered with WordPress. The export includes the member-area access record (verified address, attestation time and wording version) and notifications. Erasure removes notifications and member-area account data; the security audit log is kept, and the erasure is logged.
- **Audit log:** account details changed, email change requested, cancelled or confirmed (as `email_verified` via `email_change`), password changed, other sessions ended, privacy request made, personal data erased.
- Status notices now appear under the tabs.
- **Verified** in the local WordPress 7.1.2 install with Community Events Calendar 1.26.2:
  - New `tests/account-e2e.sh`, 64/64 over real HTTP. It covers signed-out access; the lockout for subscriber, editor, calendar manager and administrator, and with the setting off; every form's validation, nonces, rate and wrong-password limits; email-change tokens (tampered, another account's, reused, cancelled, opened signed out); session handling across devices; the privacy requests; and the exporter and eraser.
  - Existing `tests/e2e.sh`: 57/57.
  - Browser at 320/375/1440 px: no horizontal scrolling, 44 px inputs at 16 px text, and "Show passwords" works.
  - `php -l` and `node --check` pass.

## 0.1.2

- The administrator-only "this page is not set as the member area page" note no longer appears by mistake in Elementor's editor. The editor renders the shortcode outside the normal page request, so the check now also recognises the page ID Elementor passes (`elementor-preview`, `editor_post_id`) and `get_the_ID()`.

## 0.1.1

Fixes from the first run on the staging site (Elementor Cloud, Hello Elementor theme).
- **Looks like the rest of the site.** Headings now use the site's display font (Archivo Black, uppercase, lime; section titles pink), buttons match the calendar's uppercase pill buttons, and cards have the site's pink border. Also fixes an invalid `font:` shorthand on buttons that browsers ignored, which let the theme's button font show through.
- **Styled in the Elementor editor too.** The stylesheet now also loads in Elementor's preview, and in the page `<head>` on the member page so it never flashes unstyled.
- **Signing in no longer risks showing the signed-out page.** Elementor Cloud replaces the member page's `Cache-Control: no-store` with `public, max-age=300` for signed-out visitors (Hosting Check report, 2026-10-04), so a browser could show its stored "Sign in" copy for up to 5 minutes after the visitor signed in. The page now also sends `Vary: Cookie`, and the sign-in link returns to a unique member-page address. Signed-in responses were already uncached (`private, no-store` survives, Cloudflare `DYNAMIC`).

## 0.1.0

**Phase 2 increment 2.0: plugin foundation and the member-area access gate.** Built to the approved plan (decisions D1: plugin name, D2: email verification).

- **Access gate (`CMP_Access`).** A member is an account that is signed in, has verified its *current* email address, and has accepted the 18+ self-attestation, in that order. Checked on the server for the member page and every `cmp/v1` REST route. WordPress user ID is the only identity; no second login.
- **Email verification (`CMP_Email_Verification`).** The site's existing registration logs people in without verifying their address, so the member area sends its own link: 256-bit token from `random_bytes()`, only the SHA-256 hash stored, single use, 7-day expiry, bound to the address it was sent to, resend limited to once per 5 minutes. Works when opened on another device or while signed out. Verification is tied to the address itself, so changing an email requires verifying the new one; any outstanding link for the old address stops working.
- **18+ self-attestation.** Stores only a UTC timestamp and the wording version (never a date of birth or ID). The wording is editable under Settings → Member Planning and ships as clearly marked placeholder text until the owner approves it (decision D8). Changing it creates a new version; members aren't asked again.
- **In-app notifications (`CMP_Notifications`).** Inbox on the member home with mark-one and mark-all-as-read. Quiet hours (10:00 p.m.–8:00 a.m. in the member's timezone, site timezone until members can set their own) hold a notification until 8:00 a.m. Per-category opt-out is stored and enforced (its settings screen comes with profiles). Categories: account, invitation, access_change, assignment, due_reminder, submission, review_decision.
- **Append-only audit log (`CMP_Audit`).** Actor, action, object, old/new value (JSON), reason, UTC timestamp. No update or delete method exists. Logged so far: verification sent, email verified, email changed, age attested, settings changed.
- **REST API `cmp/v1`:** `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all`. Every route requires a full member; someone else's notification ID behaves exactly like a missing one (404). All `cmp/v1` responses, errors included, are sent `Cache-Control: private, no-store` and `X-Robots-Tag: noindex`.
- **Member page protection:** the configured page gets no-store headers, `DONOTCACHEPAGE` (honoured by the common page-cache plugins), robots `noindex, nofollow`, and is removed from the WordPress sitemap and site search.
- **Settings → Member Planning:** member page, attestation wording, privacy notice link, and an off-by-default "delete all member data when the plugin is deleted". Deactivating never deletes anything.
- **UI:** step indicator, notices with icon + text (never colour alone), named error states (validation, expired session, rate limit, mail failure, offline, permission denied, server error with retry), 44px buttons, scoped and `!important`-hardened against the theme per the events plugin's rule. Reuses the events plugin's colour variables with fallbacks.
- **Verified in a local WordPress 7.1.2 install** (PHP 8.3, SQLite) with Community Events Calendar 1.26.0, over real HTTP with real logins: 57/57 end-to-end checks covering logged-out, unverified, unattested, member and a second unrelated member (cross-account access, tampered/expired/reused/old-address tokens, rate limiting, nonces, headers, sitemap/search exclusion, quiet hours, audit trail). Deactivation and uninstall (setting off and on) checked; the events plugin's data and output are untouched. Browser check at 320/375/768/1440 px: no horizontal scrolling, mark-as-read works. `php -l` and `node --check` pass; zero PHP warnings in `debug.log`.
