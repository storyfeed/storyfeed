# README samples

Real feed output for the README, from a made-up street fair: every name, booking
and permit is invented.

`showcase-{light,dark}.svg` is a 15.7-second loop on a transparent ground.
A made-up street fair's feed grows, top first: a booking gains its person, a key-value card and a
photo arrive, three joins fold into one grouped row, and photos arrive live
and regroup. The camera pulls back: the feed is the picture in "You looked at
Storyfeed's README", above earlier looks. The next person's look arrives on
top, typing for a 3.0-second hold, and the camera dives into its dots, then cuts
back to the opening feed. The outer feed fades to transparent at the bottom.
Every row is a kit's own markup and stylesheet inside `foreignObject`,
animated by CSS keyframes; the typing bubble and the camera are the page's own.
Nothing runs in it. `showcase-{light,dark}.png` is a calm frame of the finished
feed, for reduced motion.
The SVG shows the same finished feed when reduced motion is requested.

These are generated: run `npm run readme:showcase` in a storyfeed/ui checkout
beside this one (its `workbench/readme/`). Regenerate them when the kits'
output changes.

`../storyfeed-header-{light,dark}.svg` is `../storyfeed-static-{light,dark}.svg`
cropped to the mark and wordmark, with no background.

Photos are from Unsplash under the [Unsplash License](https://unsplash.com/license),
the same set storyfeed/ui's workbench uses: fireworks by
[DESIGNECOLOGIST](https://unsplash.com/@designecologist), the Ferris wheel by
[Steve Shreve](https://unsplash.com/@steveshreve), the carousel by
[Sally K](https://unsplash.com/@salivan_91), the sundae by
[Molly Keesling](https://unsplash.com/@mollysuek), the pretzels by
[Khushal Shah Lakhnavi](https://unsplash.com/@legitimages) and the fair by
[Natasha Reddy](https://unsplash.com/@natashareddy).
