<?php

/***************************************************************************\
 *  SPIP, Systeme de publication pour l'internet                           *
 *                                                                         *
 *  Copyright (c) 2001-2008                                                *
 *  Arnaud Martin, Antoine Pitrou, Philippe Riviere, Emmanuel Saint-James  *
 *                                                                         *
 *  Ce programme est un logiciel libre distribue sous licence GNU/GPL.     *
 *  Pour plus de details voir le fichier COPYING.txt ou l'aide en ligne.   *
\***************************************************************************/

if (!defined("_ECRIRE_INC_VERSION")) return;

if (!defined('_LOGIN_TROP_COURT')) define('_LOGIN_TROP_COURT', 4);

// http://doc.spip.org/@action_editer_auteur_dist
function action_editer_auteur_dist() {
	$securiser_action = charger_fonction('securiser_action', 'inc');
	$arg = $securiser_action();
	$redirect = _request('redirect');
	
	if (!preg_match(",^\d+$,", $arg, $r)) {
		spip_log("action_editer_auteur_dist $arg pas compris");
	} else {
		list($id_auteur, $echec) = action_legender_auteur_post(
			_request('statut'),
			_request('nom'),
			_request('email'),
			_request('bio'),
			_request('nom_site_auteur'),
			_request('url_site'),
			_request('new_login'),
			_request('new_pass'),
			_request('new_pass2'),
			_request('perso_activer_imessage'),
			_request('pgp'),
			_request('lier_id_article'),
			intval(_request('id_parent')),
			_request('restreintes'),
			$r[0]);

			if ($echec) {
		// revenir au formulaire de saisie
				$ret = !$redirect
				? '' 
				: ('&redirect=' . $redirect);
				spip_log("echec editeur auteur: " . join(' ',$echec));
				$echec = '&echec=' . join('@@@', $echec);
				$redirect = generer_url_ecrire('auteur_infos',"id_auteur=$id_auteur$echec$ret",'&');
			} else {
			// modif: renvoyer le resultat ou a nouveau le formulaire si erreur
				if (!$redirect)
					$redirect = generer_url_ecrire("auteur_infos", "id_auteur=$id_auteur", '&', true);
				else $redirect = rawurldecode($redirect);
			}
	}
	redirige_par_entete($redirect);
}

// http://doc.spip.org/@action_legender_auteur_post
function action_legender_auteur_post($statut, $nom, $email, $bio, $nom_site_auteur, $url_site, $new_login, $new_pass, $new_pass2, $perso_activer_imessage, $pgp, $lier_id_article=0, $id_parent=0, $restreintes= NULL, $id_auteur=0) {
	global $visiteur_session;
	include_spip('inc/filtres');

	$echec = array();

	// Ce qu'on va demander comme modifications
	$c = array();

	//
	// si id_auteur est hors table, c'est une creation sinon une modif
	//
	if ($id_auteur) {
		$auteur = sql_fetsel("nom, login, bio, email, nom_site, url_site, pgp, extra, id_auteur, source, imessage", "spip_auteurs", "id_auteur=$id_auteur");
		$source = $auteur['source'];
	}
	if (!$auteur) {
		$id_auteur = 0;
		$auteur = array();
		$c['source'] = $source = 'spip';
	}

	// login et mot de passe
	if (isset($new_login)
	AND $new_login != $auteur['login']
	AND ($source == 'spip' OR !spip_connect_ldap())
	AND autoriser('modifier','auteur', $id_auteur, NULL, array('restreintes'=>1))) {
		if ($new_login) {
			if (strlen($new_login) < _LOGIN_TROP_COURT)
				$echec[]= 'info_login_trop_court';
			else {
				$n = sql_countsel('spip_auteurs', "login=" . sql_quote($new_login) . " AND id_auteur!=$id_auteur AND statut!='5poubelle'");
				if ($n)
					$echec[]= 'info_login_existant';
				else if ($new_login != $auteur['login']) {
					$c['login'] = $new_login;
					if (strlen($new_login))
						sql_updateq('spip_auteurs', array('login' => ''),
						'login='.sql_quote($new_login)." AND statut='5poubelle'");
				}
			}
		} else {
			// suppression du login
			$c['login'] = '';
		}
	}

	// creation ou changement de pass, a securiser en jaja ?
	if (strlen($new_pass)
	AND $statut != '5poubelle'
	AND $source == 'spip'
	AND autoriser('modifier','auteur', $id_auteur)
	) {
		if (isset($new_pass2) AND $new_pass != $new_pass2)
			$echec[]= 'info_passes_identiques';
		else if ($new_pass AND strlen($new_pass) < 6)
			$echec[]= 'info_passe_trop_court';
		else {
			if ($id_auteur OR $source == 'spip') {
				$htpass = generer_htpass($new_pass);
				$alea_actuel = creer_uniqid();
				$alea_futur = creer_uniqid();
				$pass = md5($alea_actuel.$new_pass);
				$c['pass'] = $pass;
				$c['htpass'] = $htpass;
				$c['alea_actuel'] = $alea_actuel;
				$c['alea_futur'] = $alea_futur;
				$c['low_sec'] = '';
			}
		}
	}

	// Si on change login ou mot de passe, deconnecter cet auteur,
	// sauf si c'est nous-meme !
	if ( (isset($c['login']) OR isset($c['pass']))
	AND $id_auteur != $visiteur_session['id_auteur']) {
		$session = charger_fonction('session', 'inc');
		$session($auteur['id_auteur']);
	}

	// Seuls les admins peuvent modifier le mail
	// les admins restreints ne peuvent modifier celui des autres admins
	if (autoriser('modifier', 'auteur', $id_auteur, NULL, array('mail'=>1))) {
		$email = trim($email);
		if ($email !='' AND !email_valide($email)) 
			$echec[]= 'info_email_invalide';
		$c['email'] = $email;
	}

	if ($visiteur_session['id_auteur'] == $id_auteur)
		$c['imessage'] = $perso_activer_imessage;

	// variables sans probleme
	$c['bio'] = $bio;
	$c['pgp'] = $pgp;
	$c['nom'] = $nom;
	$c['nom_site'] = $nom_site_auteur; // attention avec $nom_site_spip ;(
	$c['url_site'] = vider_url($url_site, false);


	//
	// Modifications de statut
	//
	if (isset($statut)
	AND autoriser('modifier', 'auteur', $id_auteur, NULL, array('statut'=>$statut)))
		$c['statut'] = $statut;

	// l'entrer dans la base
	if (!$echec) {

		// Creer l'auteur ?
		if (!$id_auteur) {
			$id_auteur = sql_insertq("spip_auteurs", array('statut' => $statut));

			// recuperer l'eventuel logo charge avant la creation
			$id_hack = 0 - $GLOBALS['visiteur_session']['id_auteur'];
			$chercher_logo = charger_fonction('chercher_logo', 'inc');
			if (list($logo) = $chercher_logo($id_hack, 'id_auteur', 'on'))
				rename($logo, str_replace($id_hack, $id_auteur, $logo));
			if (list($logo) = $chercher_logo($id_hack, 'id_auteur', 'off'))
				rename($logo, str_replace($id_hack, $id_auteur, $logo));
		}

		// Restreindre avant de declarer l'auteur
		// (section critique sur les droits)
		if ($id_parent) {
			if (is_array($restreintes))
				$restreintes[] = $id_parent;
			else
				$restreintes = array($id_parent);
		}
		if (is_array($restreintes)
		AND autoriser('modifier', 'auteur', $id_auteur, NULL, array('restreint'=>$restreintes))) {
			sql_delete("spip_auteurs_rubriques", "id_auteur=".sql_quote($id_auteur));
			foreach (array_unique($restreintes) as $id_rub)
				if ($id_rub = intval($id_rub)) // si '0' on ignore
					sql_insertq('spip_auteurs_rubriques', array('id_auteur' => $id_auteur, 'id_rubrique'=>$id_rub));
		}

		include_spip('inc/modifier');

		// Lier a un article
		if (($id_article = intval($lier_id_article))
		AND autoriser('modifier', 'article', $id_article)) {
			sql_insertq('spip_auteurs_articles', array('id_article' => $id_article, 'id_auteur' =>$id_auteur));
		}

		// Modifier en base (declenche les notifications etc.)
		instituer_auteur($id_auteur, $c);
	}

	return array($id_auteur, $echec);
}


function instituer_auteur($id_auteur, $c) {
	if (!$id_auteur=intval($id_auteur))
		return false;

	if (isset($c['statut']))
		sql_updateq('spip_auteurs', array('statut' => $c['statut']), 'id_auteur='.$id_auteur);

	revision_auteur($id_auteur, $c);
}

?>
