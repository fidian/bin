#!/usr/bin/python
# Dropbox CLI

# Bugs? Suggestions? Please, dont hesitate to mail me.
# Filip Lundborg (filip@mkeyd.net)

# This does many things wrong (global status etc), but I'll let it be here anyway if someone wants
# to change anything.
# A more working script must be my status.py. (https://dl.getdropbox.com/u/43645/status.py)


import socket
import os
import re
import sys
import time
import urllib2

# TODO: 
#	) Fix get_file so that it's more easy to understand. And add more security (a lot more).
#	) A function in the class that reads on iface_socket
#	) Add support for registering with the client

# Things I've found but dont know what they do/dont have any use for yet:
# 
# tray_action_get_menu_options (with following argument is_active\ttrue)
# is_out_of_date (argument... current version?)
# get_dropbox_globals (known arguments: keys active version mail icon_state)
# on_x_server (arguments: display)

class Dropbox:
	def __init__(self):
		self.connected = False

	def connect(self, cmd_socket="~/.dropbox/command_socket", iface_socket="~/.dropbox/iface_socket"):
		"Connects to the Dropbox command_socket, returns True if it was successfull."
		self.iface_sck=socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
		self.sck=socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
		try:
			self.sck.connect(os.path.expanduser(cmd_socket)) # try to connect
			self.iface_sck.connect(os.path.expanduser(iface_socket))
		except:
			self.connected = False
			return False
		else: # went smooth
			self.connected = True
			return True
	
	def disconnect(self):
		"Tries to disconnect, returns True/False."
		self.sck.close()
		self.connected=False
	
	def send_and_fetch(self, msg):
		"Sends a message to the command_socket, and returns the result."
		if not self.connected:
			return "notok","not connected"

		# TODO: check the message for strange and forbidden(?) chars

		self.sck.send(msg)
		res=""
		while (not res.endswith("done\n")):
			tmp=self.sck.recv(512)
			res+=tmp
			if (tmp==""):
				self.connected=False
				return ("notok","disconnected")

		return res

	def get_status(self, file):
		"""Fetches the status and context options for the specified file/folder.

			Returns 
				("unwatched",)        -> If it's unwatched
				("notok",errormsg)    -> On error
				If all went as planned, and it's a file:
					("ok",(status, context options))
				A folder:
					("okf",(status, context options, folder tag))
		"""
		if not self.connected:
			return ("notok","not connected")

		res=self.send_and_fetch("icon_overlay_file_status\npath\t%s\ndone\n" % file)
		
		res=res.split('\n')[:-1]
		if (res[0] == "ok"):
			# went ok
			res=res[1].split('\t')
			if (res[1] == "unwatched"):
				return ("unwatched",)
			else:
				status=res[1] # should be "up to date" or "syncing" (at least that's the two I've seen so far)

			 	# now we want the context options
				# and we know for fact that the file exists and are watched
				res=self.send_and_fetch("icon_overlay_context_options\npaths\t%s\ndone\n" % file)

				res=res.split('\n')[:-1]
				if (res[0] == "ok"):
					res=res[1].split('\t')[1:]
					options=[]
					for option in res:
						tmp=option.split("~")
						options.append((tmp[-1],tmp[:-1]))

					if (os.path.isdir(file)):
						# it's a directory
						res=self.send_and_fetch("get_folder_tag\npath\t%s\ndone\n" % file)

						res=res.split("\n")[:-1]
						if (res[0]=="ok"):
							# went ok
							res=res[1].split("\t")
							return ("okf",status,options,res[1])
						else: # not ok? eh?
							return ("notok",res[1])
					else:
						# it's a regular file
						return ("ok",status,options)
				else:
					# uhm, cant see what would go wrong here. but we need to check
					return ("notok",res[1])
		else:
			# didnt go that well
			return ("notok",res[1])
	
	def action(self, file, action):
		"""Sends an action to the Dropbox-daemon.

		Options I've discovered so far

		On files:
			revisions, copypublic (only if it's in the Public-folder)

		On folders:
			share, browse, copygallery (only on the Gallery folder)

		All these options opens up in your default browser (chosen by gnome-open)."""
		if (not self.connected):
			return ("notok","not connected")

		# I dont really understand the iface_socket yet, seems to output a "nop\done\n" every now
		# and then when it has nothing to do?...
		# we need to listen to the iface_socket to get the information from the action-command
		res=""; i=500
		while (len(res)!=9 and i>0): # 9 is the magic number, it's the number of the length "nop\ndone\n"
			res=self.iface_sck.recv(1024) # hopefully clear the socket of all incoming 'nop's. or we will loop forever :D:D
			i-=1
		

		self.sck.send("icon_overlay_context_action\nverb\t%s\npaths\t%s\ndone\n" % (action,file))
		res=""
		while (not res.endswith("done\n")):
			res+=self.sck.recv(512)

		res=res.split("\n")[:-1]
		if (res[0] == "ok"):
			# we got a OK, means there should (:S I guess) be a present for us in the iface_socket
			cmd=""; i=5 	# i = number of nops we should accept until we just dont care any more
								# they shouldnt be that many since we connected right before the action
			tmpres=""
			while (cmd==""):
				if (i==0):
					return ("kindaok")
				i-=0

				tmpres+=self.iface_sck.recv(512)
				tmp=tmpres.split('\n')[:-1]
				
				while tmp!=[]:
					tmpcmd=tmp[0]
					if (tmpcmd=="shell_touch" or tmpcmd=="copy_to_clipboard" or tmpcmd=="launch_url"):
						# it's a command. now look if there's one following item
						if (len(tmp)>1):
							cmd=tmpcmd
							stuff=tmp[1]
							break
						else: break
					else:
						tmp=tmp[1:]

			return ("ok",(cmd,stuff))
		else:
			return ("notok",res[1])

	def get_general_status(self):
		"Returns the status for the whole Dropbox-folder."
		if (not self.connected):
			return ("notok","not connected")

		res=self.get_status(os.path.expanduser("~/Dropbox/"))

		# TODO: error check here
		return ("ok",res[1])


# ---- DAEMON THINGY

def d_install(db, args):
	if db.connected==True:
		print "The daemon is already installed and running."
		# TODO: print version info of the daemon (should be able to get through db)
		return
	
	available=["x86","x86_64"]
	if not args[0] in available:
		print "Specified architecture are not supported.\nSupported:"
		for arch in available:
			print "  ",arch
		exit(0)

	try:
		url=urllib2.urlopen("http://www.getdropbox.com/download?plat=lnx."+args[0])
	except:
		print "Couldn't fetch the daemon from the dropbox webpage. Try again later?"
		exit(0)

	home=os.path.expanduser("~")
	filename=url.url.split('/')[-1]
	os.system("rm -r %s/.dropbox-dist/" % home)
	os.system("mkdir %s/.dropbox-dist/" % home)
	file_out=open("%s/.dropbox-dist/%s" % (home,filename),"wb")

	if ("content-length" in url.headers.keys()):
		total_bytes=int(url.headers["content-length"])
		org_shows=total_bytes/10 # when we should show output
		total_bytes/=1024 # in kB instead
		total_bytes=str(total_bytes)+" kB"
	else: total_bytes="N/A kB"
	
	print "Starting to download %s..." % filename

	read_bytes=0
	res="notempty"
	shows=0
	while (res!=""):
		res=url.read(1024)
		file_out.write(res)
		read_bytes+=1024
		shows-=1024
		if (shows<0):
			shows=org_shows
			print "  ... %d kB of %s" % (read_bytes/1024,total_bytes)
	
	file_out.close()
	print "Done. Unpacking..."

	os.system("tar xzf %s/.dropbox-dist/%s -C %s" % (home,filename,home))

	d_start()


def d_start():
	print "Trying to start the daemon."
	file = os.path.expanduser("~/.dropbox-dist/dropboxd")

	if (os.path.isfile(file)):
		os.system(file)
		return True
	else:
		print "The daemon does not exist (try %s install)" % sys.argv[0]
		return False

# ---- CLI

# TODO: make a argument that enables launch_url to open up the page in your webbrowser
def f_action(db, args):
	# first off, we need to know if the file/folder can take such an action
	file=os.path.expanduser(os.path.expandvars(args[1]))
	status=db.get_status(file)
	if status[0].startswith("ok"):
		options=dict(status[2])
		if (args[0] in options or args[0] == "copygallery"):
			res=db.action(file,args[0])
			if res=="kindaok"	: print "done, but didnt get anything in return. greedy droboxd :("
			if res[0]=="ok"	: 
				print "done, got:"
				print "   %s - %s" % (res[1][0],res[1][1].split('\t')[1])
			else					: print "error: %s" % res[1]
		else: 
			print "Unable to do \"%s\" on \"%s\".\nAvailable options on this file are:" % (args[0],file)
			for option in options.keys(): print "  ",option,"-",options[option][-1]
	else: print "error: %s" % status[1]

def f_general_status(db):
	res=db.get_general_status()
	if res[0]=="ok":
		print res[1]
	else:
		print "error: "+res[1]

def f_file_status(db, args):
	status=db.get_status(args[0])

	if (status[0].startswith("ok")):
		print "%s status: %s" % ("Folder" if (status[0]=="okf") else "File", status[1])
		if status[0]=="okf": print "Folder is tagged as: %s" % status[3]
		print "Available options for the %s:" % ("folder" if (status[0]=="okf") else "file")
		options=dict(status[2])
		for option in options:
			print "   %s - %s" % (option,options[option][-1])
	else:
		print "error: %s" % status[1]

def usage(trash=""): # TODO: the trash-thingy is ugly... fix.
	print "Usage: %s <command> [options] ... <command> [options]" % sys.argv[0]
	print "Available commands:"
	for c in func.keys():
		f=func[c]
		str="   %s " % c
		if f[0]==0: discr=f[1] # no arguments
		else: # arguments
			discr=f[2]
			for arg in f[1]: str+="<%s> " % arg

		print str+"- " + discr

# command: (number of arguments, (if numargs > 0 : [args],) explanation, (function to call,[args]))
func={"copypublic"	: (1,["file"],"Copies the url to the clipboard (? I guess)",(f_action,["copypublic"])), \
		"copygallery"	: (0,"Copies the gallery url to the clipboard.",(f_action,["copygallery","~/Dropbox/Photos"])), \
		"revisions"		: (1,["file"],"Opens the revisions page in your browser for the specified file.",(f_action,["revisions"])), \
		"share"			: (1,["file"],"Open the browser and shows share-information.",(f_action,["share"])), \
		"browse"			: (1,["folder"],"Browse the specified folder in your browser.",(f_action,["browse"])), \
		"status"			: (0,"Get overall status for the daemon.",(f_general_status,[])), \
		"file"			: (1,["file"],"Get information about a file/folder.",(f_file_status,[])), \
		"folder"			: (1,["folder"],"Get information about a file/folder.",(f_file_status,[])), \
		"install"		: (1,["x86/x86_64"],"Tries to download the daemon and install it in your home directory.",(d_install,[])), \
		"help"			: (0,"Gives this.",(usage,[])) \
		} # TODO: add start/stop-functions for the daemon, and if possible, update (dont know how that works.)

if __name__ == "__main__":
	args = sys.argv[1:]

	if (len(args) == 0):
		usage()
		exit(0)

	install=False # TODO: do this "smarter"
	todo=[] # list of all things we should do.
	# but first we need to get them, and check so there's no invalid commands/options
	while (args!=[]): # handle the commands
		f=args[0].lower()
		if f=="install": install=True
		if (f in func):	# it's a command
			tmp=func[f]
			if (tmp[0]>(len(args)-1)):
				print "Not enough options for \"%s\"." % f
				str="Usage: %s %s " % (sys.argv[0],f)
				for arg in tmp[1]:
					str+="<%s> " % arg
				print str
				exit(0)
			else:
				fargs=args[1:tmp[0]+1]
				args=args[tmp[0]+1:]
				todo.append((tmp[-1][0],tmp[-1][1]+fargs))

		else: # it's unknown
			print "Unknown command \"%s\"\n" % f
			usage()
			exit(0)

	db=Dropbox()
	if (db.connect()==False):
		print "error: couldn't connect to the daemond."
		if (d_start()==False):
			if (install==False):
				exit(0)
		else:
			while (db.connect()==False):
				print "Trying to reconnect..."
				time.sleep(1)
			print "It's started and we're connected."
			time.sleep(5) # let it start etc

	# and then we should do them all
	for f,a in todo: # TODO: the functions should not need a tuple from func.
		if a==[]: 	f(db)
		else:			f(db,a)
	
	db.disconnect()
