#!/bin/bash
set -eo pipefail
cd ~
MYSELF="${0##*/}"
CMD="$1"
set -u

function initializedCheck {
	if [ -h homeconnections_backend ] && [ -h homeconnections_ui ]; then
		CURRENT="$(readlink homeconnections_ui | cut -d '-' -f 2-)"
	else
		echo "Environment not initialized.  Use '$MYSELF initialize' to set up things"
		exit 1
	fi

	if [ "$CURRENT" == "/dev/null" ] && [ "$CMD" != "create" ] && [ "$CMD" != "use" ]; then
		echo "You need to create an environment.  Use '$MYSELF create' for help."
		echo "If you have one already, use '$MYSELF list' to see what you have"
		echo "and then '$MYSELF use' to go to one."
		exit 1
	fi
}

case "$CMD" in
    create)
		set +u
		NAME="$2"
		BACKEND="$3"
		UI="$4"
		set -u
		if [ -z "$BACKEND" ]; then
			echo "Specify the branch or branches to use"
			echo ""
			echo "$MYSELF create NAME BRANCH"
			echo "    Checks out a single branch for both the backend and ui"
			echo "$MYSELF create NAME BACKEND-BRANCH UI-BRANCH"
			echo "    Uses different branches for the backend and ui"
			echo ""
			echo "Examples:"
			echo "  Check out 'develop' for both backend and ui"
			echo "    $MYSELF create develop develop"
			echo "  Work on CEM errors with backend as develop and ui as errors"
			echo "    $MYSELF create cem develop errors"
			exit 0
		fi
		if [ -z "$UI" ]; then
			UI="$BACKEND"
		fi
		(
			git clone --branch "$BACKEND" utilities:homeconnections_backend.git "homeconnections_backend-$NAME"
			(
				cd "homeconnections_backend-$NAME"
				cat <<EOF > app/config/parameters_override.yml
parameters:
    database_name: ${USER}_homeconnections-${NAME}
EOF
				util/bin/setup_repository
			)
			git clone --branch "$UI" utilities:homeconnections_ui.git "homeconnections_ui-$NAME"
			(
				cd "homeconnections_ui-$NAME"
				util/bin/setup_repository
				make
			)
		) || (
			echo "Error detected"
			rm -rf "homeconnections_backend-$NAME" "homeconnections_ui-$NAME"
		)
		echo ""
		echo "Created environment.  Use '$MYSELF use $NAME' to start using it"
		;;

	fix)
		initializedCheck || exit 1
		cat <<EOF
This is an intense scrubber that will remove uncomitted changes as well as
extra files and directories.  It does several things that may take a long time
to complete.  Use this when you can't figure out what to do and you feel
confident that your work has already been pushed to origin.

EOF
		read -p "Are you positive you want to do this? [y/n] " CONFIRM
		CONFIRM="${CONFIRM:0:1}"
		if [ "${CONFIRM^^}" != "Y" ]; then
			echo "Aborting"
			exit 0
		fi
		for D in homeconnections_backend homeconnections_ui; do
			(
				cd "$D"
				# Make sure the repository is ok
				git fsck
				# Revert to the last commit
				git reset --hard HEAD
				# Remove locally changed files without running hooks
				git checkout-index -a -f
				# Clean up extra files and directories
				git clean -X -f -d
				# Remove auto-generated things
				rm -rf vendor node_modules
				# Do the updates
				util/bin/setup_repository
				# Update the code
				git pull
				# Force hooks to run
				git checkout .
			) || echo "   ------------------------------- FAIL ------------------------"
		done
		;;

	initialize)
		cat <<EOF
This wipes out your current Home Connections environments.
You could lose work if you did not commit and push your changes.

If you already had an initialized environment, this will wipe all of your
configured environments as well, but their databases will not be removed.
You may need to manually go in and remove those databases.

EOF
		read -p "Are you positive you want to do this? [y/n] " CONFIRM
		CONFIRM="${CONFIRM:0:1}"
		if [ "${CONFIRM^^}" != "Y" ]; then
			echo "Aborting"
			exit 0
		fi
		echo "Clearing out repositories"
		rm -rf homeconnections_ui-* homeconnections_ui homeconnections_backend-* homeconnections_backend
		echo "Linking /dev/null to repository directories"
		ln -s /dev/null homeconnections_ui
		ln -s /dev/null homeconnections_backend
		echo "Done.  Use '$MYSELF create' to make a new environment"
		;;

	list|ls)
		initializedCheck || exit 1
		COUNT=0
		for E in homeconnections_ui-*/; do
			FLAG="   "
			E="${E%/}"
			E="${E#*-}"
			if [ "$CURRENT" == "$E" ]; then
				FLAG=" * "
			fi
			BACKEND_BRANCH="$(cd homeconnections_backend-$E && git rev-parse --abbrev-ref HEAD || echo "unknown")"
			UI_BRANCH="$(cd homeconnections_ui-$E && git rev-parse --abbrev-ref HEAD || echo "unknown")"
			echo "${FLAG}$E - backend $BACKEND_BRANCH, ui $UI_BRANCH"
			COUNT=$(( $COUNT + 1 ))
		done
		if [ $COUNT -eq 0 ]; then
			echo "No environments available" > /dev/stderr
			exit 1
		fi
		;;

	localize)
		cat <<EOF
Setting up a dev environment so it runs locally on a laptop or a standard
Linux installation requires further changes to the permissions of some
directories, address standardization must be stubbed and other minor tweaks.

Only continue if you are not on your Amazon instance.

EOF
	    read -p "Make these changes? [y/n] " CONFIRM
		CONFIRM="${CONFIRM:0:1}"
		if [ "${CONFIRM^^}" != "Y" ]; then
			echo "Aborting"
			exit 0
		fi
		(
			cd homeconnections_backend/app

			echo "Allowing the web server read/write access to cache, local, logs"
			mkdir -p cache local logs
			chmod g+s cache local logs
			chmod 777 cache local logs
			rm -rf cache/* local/* logs/*

			echo "Stubbing address standardization"
			TMP="$(mktemp)"
			grep -v AddressStandardizationStub config/parameters_override.yml >> "$TMP"
			echo "    address_standardization_class: BBY\\AddressStandardizationBundle\\Services\\AddressStandardizationStub" >> "$TMP"
			cat "$TMP" > config/parameters_override.yml
			rm "$TMP"

			echo "Making the local config writable"
			chmod g+s config/parameters_default.yml
			chmod 777 config/parameters_default.yml
		)
		;;
		
	pull)
		initializedCheck || exit 1
		for D in homeconnections_backend homeconnections_ui; do
			(
				cd "$D"
				git pull
			)
		done
		;;

	remove|rm)
		initializedCheck || exit 1
		set +u
		NAME="$2"
		set -u
		if [ -z "$NAME" ]; then
			NAME="$CURRENT"
		fi
		echo "Removing $NAME"
		rm -rf "homeconnections_ui-$NAME" "homeconnections_backend-$NAME"
		echo "Dropping table"
		echo "drop database \`${USER}_homeconnections-${NAME}\`" | mysql -u e2save --password=YhNbGt -h db || echo "Already removed? - ignoring error"
		if [ "$NAME" == "$CURRENT" ]; then
			echo "Removed the current environment."
			ln -s -f "/dev/null" homeconnections_ui
			ln -s -f "/dev/null" homeconnections_backend
			echo "Now use '$MYSELF list' and '$MYSELF use' to select a new environment"
		else
			echo "Removed $NAME"
		fi
		;;

	update)
		echo "Copying from utilities:hc to $0"
		echo "Sometimes this will give you error messages after the update"
		scp utilities:hc "$0"
		;;

	use)
		initializedCheck || exit 1
		set +u
		NAME="$2"
		set -u
		if [ -z "$NAME" ]; then
			echo "Specify environment to use." > /dev/stderr
			echo "Use '$MYSELF list' to see your options." > /dev/stderr
			exit 1
		fi
		if [ ! -d "homeconnections_ui-$NAME" ]; then
			echo "Environment does not exist." > /dev/stderr
			exit 1
		fi
		echo "Switching to use the $NAME environment"
		ln -s -f -n "homeconnections_ui-$NAME" homeconnections_ui
		ln -s -f -n "homeconnections_backend-$NAME" homeconnections_backend
		;;
	
	*)
		cat <<EOF
Specify a command:

create      Make a new environment
fix         Run many commands to try to fix an environment
initialize  Wipe out Home Connections repositories and start new
localize    Change an installation to work for people with local dev installs
list        List the prepared environments
pull        Update the current environment
remove      Remove an environment (current one or the specified one)
update      Get a new version of this script
use         Switch to a given environment
EOF
		;;
esac
