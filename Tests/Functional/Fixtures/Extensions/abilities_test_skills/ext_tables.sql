CREATE TABLE tx_nrllm_skill (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
	hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
	enabled smallint(6) DEFAULT '1' NOT NULL,
	identifier varchar(512) DEFAULT '' NOT NULL,
	name varchar(255) DEFAULT '' NOT NULL,
	description text,
	raw_frontmatter text,
	allowed_tools text,

	PRIMARY KEY (uid)
);

CREATE TABLE tx_skillflow_skill (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
	hidden smallint(5) unsigned DEFAULT '0' NOT NULL,
	identifier varchar(255) DEFAULT '' NOT NULL,
	title varchar(255) DEFAULT '' NOT NULL,
	description text,
	metadata longtext,
	allowed_tools text,

	PRIMARY KEY (uid)
);
