#!/usr/bin/env bash

# From https://gist.github.com/markusfisch/2648733

# Fallback base64 en-/decoder for systems that lack a native implementation
#
# @param ... - flags
which base64 &>/dev/null || {
# if even od is missing
which od &>/dev/null || od()
{
	local C O=0 W=16
	while IFS= read -d '' -n 1 C
	do
		(( O%W )) || printf '%07o' $O
		printf ' %02x' "'$C"
		(( ++O%W )) || echo
	done
	echo
}
if which awk &>/dev/null
then
base64()
{
	# written by Danny Chouinard
	# https://sites.google.com/site/dannychouinard/Home/unix-linux-trinkets/little-utilities/base64-and-base85-encoding-awk-scripts
	awk \
'function encode64()
{
	while( "od -v -t x1" | getline )
	{
		l = length( $0 );
		for( c = 9; c <= l; ++c )
		{
			d = index( "0123456789abcdef", substr( $0, c, 1 ) );

			if( d-- )
			{
				for( b = 1; b <= 4; ++b )
				{
					o = o*2+int( d/8 );
					d = (d*2)%16;

					if( ++obc == 6 )
					{
						printf substr( b64, ++o, 1 );

						if( ++rc > 75 )
						{
							printf( "\n" );
							rc = 0;
						}

						obc = 0;
						o = 0;
					}
				}
			}
		}
	}

	if( obc )
	{
		while( obc++ < 6 )
		{
			o = o*2;
		}

		printf "%c", substr( b64, ++o, 1 );
	}

	print "==";
}
function decode64()
{
	while( getline < "/dev/stdin" )
	{
		l = length( $0 );
		for( i = 1; i <= l; ++i )
		{
			c = index( b64, substr( $0, i, 1 ) );
			if( c-- )
			{
				for( b = 0; b < 6; ++b )
				{
					o = o*2+int( c/32 );
					c = (c*2)%64;

					if( ++obc == 8 )
					{
						printf "%c", o;
						obc = 0;
						o = 0;
					}
				}
			}
		}
	}
}
BEGIN {
	b64="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"

	if( ARGV[1] == "-d" )
		decode64();
	else
		encode64();
}' $@
}
else
cat <<EOF

WARNING: your system is missing base64 AND awk!
         base64 encoding/decoding will be painfully slow!

EOF
base64()
{
	local SET='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'

	[ "$1" == '-d' ] && {
		local N=0 V=0 C S IFS=

		while read -d '' -r -n1 C
		do
			[ $C == $'\n' ] && continue

			if [ $C == '=' ]
			then
				V=$(( V << 6 ))
			else
				C=${SET#*$C}
				C=$(( ${#SET}-${#C} ))
				(( C )) || continue

				V=$(( V << 6 | --C ))
			fi

			(( ++N == 4 )) && {
				for (( S=16; S > -1; S -= 8 ))
				do
					C=$(( V >> S & 255 ))
					printf \\$(( $C/64*100+$C%64/8*10+$C%8 ))
				done

				V=0
				N=0
			}
		done

		return
	}

	od -v -t x1 | {
		local V=0 W=0 SH=16 A S N L

		while read -a A
		do
			for (( N=1, L=${#A[@]}; N < L; ++N ))
			do
				V=$(( 16#${A[$N]} << SH | V ))

				(( (SH -= 8) < 0 )) || continue

				for (( S=18; S > -1; S -= 6 ))
				do
					echo -n ${SET:$(( V >> S & 63 )):1}

					(( ++W > 75 )) && {
						echo
						W=0
					}
				done

				SH=16
				V=0
			done
		done

		if (( SH == 8 ))
		then
			N=11
		elif (( SH == 0 ))
		then
			N=5
		else
			N=0
		fi

		(( N )) && {
			for (( S=18; S > N; S -= 6 ))
			do
				echo -n ${SET:$(( V >> S & 63 )):1}

				(( ++W > 75 )) && {
					echo
					W=0
				}
			done

			for (( S=N/5; S--; ))
			do
				echo -n '='
			done
		}

		echo
	}
}
fi
}

if [ "$BASH_SOURCE" == "$0" ]
then
	base64 $@
fi
