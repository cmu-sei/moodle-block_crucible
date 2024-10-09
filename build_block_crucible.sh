#/bin/bash
rm -f block_crucible_*.zip

OLDVERSION=`grep "plugin->version" crucible/version.php | sed -e "s/.*= \(.*\);.*/\1/"`
OLDDATE=`echo $OLDVERSION | cut -b 1-8`
NEWDATE=`date +%Y%m%d`
if [ $OLDDATE -ne $NEWDATE ]; then
    NEWVERSION=$NEWDATE\01
else
    OLDVERS=`echo $OLDVERSION | cut -b 9-10`
    OLDVERS=${OLDVERS#0}
    let NEWVERS=(OLDVERS + 1)
    NEWVERSION=$OLDDATE`printf %02d $NEWVERS`
fi
echo old version $OLDVERSION
echo new version $NEWVERSION
sed -i "s/$OLDVERSION/$NEWVERSION/" crucible/version.php

zip -r block_crucible_$NEWVERSION.zip crucible/ --exclude crucible/.git/\* crucible/.gitignore crucible/node_modules/\* crucible/package-lock.json crucible/Gruntfile.js
