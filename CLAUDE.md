## Deploy rule
Never deploy by copying files, tarballs or bundles to the server.
Deploy only by: push to origin, then on the server run
git fetch origin && git merge --ff-only origin/<branch>,
then composer install, npm run build, migrate --force, cache rebuild,
queue:restart.
Before building, run node -v on the server and confirm it is v22.
Never run git clean or npm audit fix --force on the server.
After every deploy, run git status and git log -1 on the server.
The deploy is not done unless the working tree is clean (only
storage/ entries allowed) and HEAD matches the hash you pushed.
Report both.
