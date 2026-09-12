CREATE TABLE tx_abilities_trace (
	ability varchar(255) DEFAULT '' NOT NULL,
	surface varchar(16) DEFAULT '' NOT NULL,
	ok smallint unsigned DEFAULT '0' NOT NULL,
	error_code varchar(64) DEFAULT '' NOT NULL,
	error text,
	input text,
	duration_ms int unsigned DEFAULT '0' NOT NULL,
	be_user int unsigned DEFAULT '0' NOT NULL,

	KEY ability (ability(191)),
	KEY crdate (crdate)
);

CREATE TABLE tx_abilities_token (
	name varchar(255) DEFAULT '' NOT NULL,
	token_hash varchar(64) DEFAULT '' NOT NULL,
	be_user int unsigned DEFAULT '0' NOT NULL,
	scopes text,
	expires int unsigned DEFAULT '0' NOT NULL,
	last_used int unsigned DEFAULT '0' NOT NULL,

	UNIQUE KEY token_hash (token_hash),
	KEY be_user (be_user)
);

CREATE TABLE be_groups (
	tx_abilities_scopes text
);
