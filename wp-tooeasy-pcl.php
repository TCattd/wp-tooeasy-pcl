<?php
/**
 * Plugin Name: TooEasy Public Contact List
 * Plugin URI: http://www.attitude.cl
 * Description: An easy, REALLY easy, contact list management (birthday included) backend, with shortcode support: [contact-list] for use on any WP's page. Build as a demonstration of how to use native tables inside WordPress's admin.
 * Version: 1.0.0
 * Author: Esteban Cuevas
 * Author URI: http://esteban.attitude.cl
 * Requires at least: 3.5.1
 * Tested up to: 3.5.1
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

/*
In order to keep this simple, i will put all the code in one single file.
I know, for a big proyect it's not ideal.
But... this is not as big as some other things i usually do.

So. Please. Deal with that.
Thanks ;)

PS: all comments in spanish. Hope you know some bit of it :)
*/

//No permitimos el acceder directamente a este archivo
if ( ! defined( 'ABSPATH' ) )
	exit;

//Version de la DB
$tccl_dbversion = '1.0.0';

//La tabla que vamos a usar en la DB. Las añadimos al prefix de WP
global $wpdb, $tccl_dbversion, $pagenow;
$wpdb->tccl = $wpdb->prefix.'tccl';

//Funcion: crear la tabla requerida en la DB
function tccl_dbinstall() {
	global $wpdb;

	//El SQL de nuestra tabla
	$sql = "CREATE TABLE $wpdb->tccl (
	  id BIGINT(20) NOT NULL AUTO_INCREMENT,
	  name VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci,
	  last_name VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci,
	  email VARCHAR(150) CHARACTER SET utf8 COLLATE utf8_general_ci,
	  bday_year INT(4),
	  bday_month INT(2),
	  bday_day INT(2),
	  PRIMARY KEY  (id)
	);";

	//Invocamos dbDelta para crear la tabla
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
   	dbDelta( $sql );

   	//Marcamos esta instancia de WP con nuestra version actual de la DB
   	add_option( 'tccl_dbversion', $tccl_dbversion );
}

//Corremos nuestra funcion para crear la DB, luego de activado el plugin
register_activation_hook( __FILE__, 'tccl_dbinstall' );

//Creamos nuestro item dentro de Settings
function tccl_settingsmenu() {
	add_submenu_page( 'options-general.php', 'Contact List', 'Contact List', 'manage_options', 'tcattd-contact-list', 'tccl_contactlist_pagestart' );
	//Un pequeño truco
	add_submenu_page( null, 'Contact List Add', 'Contact List Add', 'manage_options', 'tcattd-contact-list-add', 'tccl_contactlist_pageadd' );
	add_submenu_page( null, 'Contact List Export', 'Contact List Export', 'manage_options', 'tcattd-contact-list-export', 'tccl_contactlist_pageexport' );
	add_submenu_page( null, 'Contact List Manage', 'Contact List Manage', 'manage_options', 'tcattd-contact-list-manage', 'tccl_contactlist_pagemanage' );
}
add_action('admin_menu', 'tccl_settingsmenu');

//Pagina backend - inicio
function tccl_contactlist_pagestart() {
	?>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php _e( "Contact List", "tccl" ); ?> <a href="options-general.php?page=tcattd-contact-list-add" class="add-new-h2"><?php _e("Add New Contact", "tccl" ); ?></a> <a href="options-general.php?page=tcattd-contact-list-export" onclick="return confirm('<?php _e( "Do you want to export your contact as CSV?", "tccl" ); ?>');" class="add-new-h2"><?php _e("Export to CSV", "tccl" ); ?></a></h2>

		<p>
			<?php _e( "Here you review and can control (add, edit or remove) contact information from your Contact List", "tccl"); ?>
		</p>
		<p>
			<?php _e( "To display the Contact List on any WordPress's page, use the shortcode: <code>[contact-list]</code>", "tccl"); ?>
		</p>

		<h3><?php _e( "Your Contacts", "tccl" ); ?></h3>

		<form id="tcattd-contactlist" method="get">
			<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
			<?php
				//Invocamos la tabla de Contactos
				$tccl_tabla = new tcattd_ContactTable();
				$tccl_tabla->prepare_items();
				$tccl_tabla->display();
			?>
		</form>
	</div> <!-- / .wrap -->
	<?php
}

//Pagina backend - añadir contacto
function tccl_contactlist_pageadd() {
	//Añadamos el date picker de jQuery, para el cumpleaños
	wp_enqueue_script( 'jquery-ui-datepicker' );
	wp_enqueue_style( 'jquery.ui.theme', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.10.1/themes/base/jquery-ui.css' );
	add_action( 'admin_footer', 'tccl_pageadd_footer' );

	//Veamos si estan añadiendo un contacto
	if ( isset($_POST['submit'])) {

		//No deberia estar aca si el nonce es invalido
		if ( isset( $_POST['tccl_addcontact'] ) AND wp_verify_nonce( $_POST['tccl_addcontact'], basename(__FILE__) ) ) {
			//Necesitamos el $wpdb, para insertar en la DB claro :)
			global $wpdb;

			/*
			El cumpleaños lo necesitamos separado.
			No usamos una unica columna, puesto que si esto crece (siempre hay que pensar en escalar)
			entonces una consulta como esta:
				SELECT * FROM $wpdb->tccl WHERE birthday LIKE '%-7-17'
			se volvera MUY lenta.
			Es mas sencillo (SI HAY QUE ESCALAR) el guardar separado el año, mes y dia, y luego consultar
			lo que se necesite por separado
			*/
			$birthday 	= explode("-", $_POST['birthday']);
			$bday_year 	= $birthday[0];
			$bday_month = $birthday[1];
			$bday_day	= $birthday[2];

			//Veamos si el cumpleaños es valido (mes-dia-año)
			if(true === checkdate($bday_month, $bday_day, $bday_year) AND $bday_year >= 1000 AND $bday_year <= 9999) {
				/*
				Insertemos la data en la db, sanitizando, en un solo paso
				Usando insert, http://wordpress.stackexchange.com/questions/25947/wpdb-insert-do-i-need-to-prepare-against-sql-injection
				No requiere "prepare".

				TODO: manejo de error en caso de... error... de la consulta con $wpdb
				*/
				$wpdb->insert(
					$wpdb->tccl,
					array(
						'name' 		 => $_POST['name'],
						'last_name'  => $_POST['last_name'],
						'email' 	 => $_POST['email'],
						'bday_year'  => $bday_year,
						'bday_month' => $bday_month,
						'bday_day' 	 => $bday_day,
					),
					array(
						'%s',
						'%s',
						'%s',
						'%d',
						'%d',
						'%d',
					)
				);

				$tccl_flag_addok = true;
			} else {
				//Fecha de cumpleaño invalida
				$tccl_flag_birthwrong = true;
			}
		}

	}
	?>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php _e( "Contact List", "tccl" ); ?> <a href="options-general.php?page=tcattd-contact-list" class="add-new-h2"><?php _e("View Contacts", "tccl" ); ?></a> <a href="options-general.php?page=tcattd-contact-list-export" onclick="return confirm('<?php _e( "Do you want to export your contact as CSV?", "tccl" ); ?>');" class="add-new-h2"><?php _e("Export to CSV", "tccl" ); ?></a></h2>

		<?php if( isset( $tccl_flag_addok ) ) { ?>
		<div class="updated"><p><?php _e( "Contact", "tccl" );?> <strong><?php echo wp_kses( $_POST['name'], array() ); ?> <?php echo wp_kses( $_POST['last_name'], array() ); ?></strong> <?php _e( "Successfully Added", "tccl" ); ?></p></div>
		<?php } ?>

		<?php if( isset( $tccl_flag_birthwrong ) ) { ?>
		<div class="error"><p><?php _e( "Wrong Birthdate. Please use YYYY-MM-DD format", "tccl" );?></p></div>
		<?php } ?>

		<h3><?php _e( "Add New Contact", "tccl" ); ?></h3>

		<form method="post" action="options-general.php?page=tcattd-contact-list-add">
		    <?php wp_nonce_field( basename(__FILE__), 'tccl_addcontact' ); ?>

		    <table class="form-table">
		        <tr valign="top">
		        <th scope="row"><label for="name"><?php _e( "Name", "tccl" ); ?></label></th>
		        <td><input type="text" name="name" value="" /></td>
		        </tr>

		        <tr valign="top">
		        <th scope="row"><label for="last_name"><?php _e( "Last Name", "tccl" ); ?></label></th>
		        <td><input type="text" name="last_name" value="" /></td>
		        </tr>

		        <tr valign="top">
		        <th scope="row"><label for="email"><?php _e( "E-mail", "tccl" ); ?></label></th>
		        <td><input type="text" name="email" value="" /></td>
		        </tr>

		        <tr valign="top">
		        <th scope="row"><label for="birthday"><?php _e( "Birthday", "tccl" ); ?></label></th>
		        <td><input type="text" name="birthday" value="" id="tccl_birthday" />
		        	<p class="description"><?php _e( "In YYYY-M-D format", "tccl" ); ?></p></td>
		        </tr>
		    </table>

		    <?php submit_button( __( "Add Contact" ) ); ?>

		    <p class="cancel">
		    	<input type="button" name="save" id="save-post" value="<?php _e( 'Cancel', 'tccl' ); ?>" class="button" onclick="window.location='options-general.php?page=tcattd-contact-list';" />
			</p>
		</form>
	</div> <!-- / .wrap -->

	<?php
}

//Datepicker JS
function tccl_pageadd_footer() {
	?>
	<script type="text/javascript">
		jQuery(document).ready(function(){
			jQuery('#tccl_birthday').datepicker({
				dateFormat : 'yy-m-d'
			});
		});
	</script>
	<?php
}

//Pagina backend - export to csv
function tccl_contactlist_pagemanage() {
	//Necesitamos acceso a la DB
	global $wpdb;

	//Quieren editar un registro existente
	if($_REQUEST['action'] == 'edit') {
		//Añadamos el date picker de jQuery, para el cumpleaños
		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_style( 'jquery.ui.theme', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.10.1/themes/base/jquery-ui.css' );
		add_action( 'admin_footer', 'tccl_pageadd_footer' );

		//Busquemos los datos del contacto en la DB
		$tccl_cid 	= mysql_real_escape_string( (int) $_REQUEST['id'] );
		$tccl_cq 	= $wpdb->get_row("SELECT * FROM $wpdb->tccl WHERE id = $tccl_cid LIMIT 1");

		//Cumpleaños
		$birthday = $tccl_cq->bday_year . '-' . $tccl_cq->bday_month . '-' . $tccl_cq->bday_day;

		//Veamos si estan editando un contacto
		if ( isset($_POST['submit']) ) {

			//No deberia estar aca si el nonce es invalido
			if ( isset( $_POST['tccl_addcontact'] ) AND wp_verify_nonce( $_POST['tccl_addcontact'], basename(__FILE__) ) ) {
				/*
				El cumpleaños lo necesitamos separado.
				No usamos una unica columna, puesto que si esto crece (siempre hay que pensar en escalar)
				entonces una consulta como esta:
					SELECT * FROM $wpdb->tccl WHERE birthday LIKE '%-7-17'
				se volvera MUY lenta.
				Es mas sencillo (SI HAY QUE ESCALAR) el guardar separado el año, mes y dia, y luego consultar
				lo que se necesite por separado
				*/
				$birthday 	= explode("-", $_POST['birthday']);
				$bday_year 	= $birthday[0];
				$bday_month = $birthday[1];
				$bday_day	= $birthday[2];

				//Veamos si el cumpleaños es valido (mes-dia-año)
				if(true === checkdate($bday_month, $bday_day, $bday_year) AND $bday_year >= 1000 AND $bday_year <= 9999) {
					/*
					Insertemos la data en la db, sanitizando, en un solo paso
					Usando insert, http://wordpress.stackexchange.com/questions/25947/wpdb-insert-do-i-need-to-prepare-against-sql-injection
					No requiere "prepare".

					TODO: manejo de error en caso de... error... de la consulta con $wpdb
					*/
					$wpdb->update(
						$wpdb->tccl,
						array(
							'name' 		 => $_POST['name'],
							'last_name'  => $_POST['last_name'],
							'email' 	 => $_POST['email'],
							'bday_year'  => $bday_year,
							'bday_month' => $bday_month,
							'bday_day' 	 => $bday_day,
							),
						array(
							'id' 		 => $tccl_cid,
							),
						array(
							'%s',
							'%s',
							'%s',
							'%d',
							'%d',
							'%d',
						),
						array(
							'%d',
						)
					);

					$tccl_flag_addok = true;

					//Necesitamos que el form abajo muestre los datos que editamos recien
					$tccl_cq->name 		= $_POST['name'];
					$tccl_cq->last_name	= $_POST['last_name'];
					$tccl_cq->email 	= $_POST['email'];
					$birthday 			= $bday_year . '-' . $bday_month . '-' . $bday_day;

				} else {
					//Fecha de cumpleaño invalida
					$tccl_flag_birthwrong = true;
				}
			}

		}

	?>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php _e( "Contact List", "tccl" ); ?> <a href="options-general.php?page=tcattd-contact-list" class="add-new-h2"><?php _e("View Contacts", "tccl" ); ?></a> <a href="options-general.php?page=tcattd-contact-list-export" onclick="return confirm('<?php _e( "Do you want to export your contact as CSV?", "tccl" ); ?>');" class="add-new-h2"><?php _e("Export to CSV", "tccl" ); ?></a></h2>

		<?php if( isset( $tccl_flag_addok ) ) { ?>
		<div class="updated"><p><?php _e( "Contact", "tccl" );?> <strong><?php echo wp_kses( $_POST['name'], array() ); ?> <?php echo wp_kses( $_POST['last_name'], array() ); ?></strong> <?php _e( "Edited Successfully", "tccl" ); ?></p></div>
		<?php } ?>

		<?php if( isset( $tccl_flag_birthwrong ) ) { ?>
		<div class="error"><p><?php _e( "Wrong Birthdate. Please use YYYY-MM-DD format", "tccl" );?></p></div>
		<?php } ?>

		<h3><?php _e( "Edit Existing Contact", "tccl" ); ?></h3>

		<form method="post" action="options-general.php?page=tcattd-contact-list-manage&amp;action=edit&amp;id=<?php echo $tccl_cid; ?>">
		    <?php wp_nonce_field( basename(__FILE__), 'tccl_addcontact' ); ?>

		    <table class="form-table">
		        <tr valign="top">
		        <th scope="row"><label for="name"><?php _e( "Name", "tccl" ); ?></label></th>
		        <td><input type="text" name="name" value="<?php echo $tccl_cq->name; ?>" /></td>
		        </tr>

		        <tr valign="top">
		        <th scope="row"><label for="last_name"><?php _e( "Last Name", "tccl" ); ?></label></th>
		        <td><input type="text" name="last_name" value="<?php echo $tccl_cq->last_name; ?>" /></td>
		        </tr>

		        <tr valign="top">
		        <th scope="row"><label for="email"><?php _e( "E-mail", "tccl" ); ?></label></th>
		        <td><input type="text" name="email" value="<?php echo $tccl_cq->email; ?>" /></td>
		        </tr>

		        <tr valign="top">
		        <th scope="row"><label for="birthday"><?php _e( "Birthday", "tccl" ); ?></label></th>
		        <td><input type="text" name="birthday" value="<?php echo $birthday; ?>" id="tccl_birthday" />
		        	<p class="description"><?php _e( "In YYYY-M-D format", "tccl" ); ?></p></td>
		        </tr>
		    </table>

		    <?php submit_button( __( "Save Contact" ) ); ?>

		    <p class="cancel">
		    	<input type="button" name="save" id="save-post" value="<?php _e( 'Cancel', 'tccl' ); ?>" class="button" onclick="window.location='options-general.php?page=tcattd-contact-list';" />
			</p>
		</form>
	</div>
	<?php
	}

	//Quieren borrar un registro existente
	if($_REQUEST['action'] == 'delete') {
		$tccl_idtodelete = mysql_real_escape_string( (int) $_REQUEST['id'] );

		//TODO: verificar si hubo error en la consulta con $wpdb
		$wpdb->query(
			$wpdb->prepare(
				"
				DELETE FROM $wpdb->tccl
				WHERE id = %d
				LIMIT 1
				",
				$tccl_idtodelete
			)
		);
	?>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php _e( "Contact List", "tccl" ); ?> <a href="options-general.php?page=tcattd-contact-list" class="add-new-h2"><?php _e("View Contacts", "tccl" ); ?></a> <a href="options-general.php?page=tcattd-contact-list-add" class="add-new-h2"><?php _e("Add New Contact", "tccl" ); ?></a> <a href="options-general.php?page=tcattd-contact-list-export" onclick="return confirm('<?php _e( "Do you want to export your contact as CSV?", "tccl" ); ?>');" class="add-new-h2"><?php _e("Export to CSV", "tccl" ); ?></a></h2>

		<div class="error"><p><?php _e( "Contact", "tccl" );?> <strong>ID = <?php echo wp_kses( $_REQUEST['id'], array() ); ?></strong> <?php _e( "Deleted", "tccl" ); ?></p></div>
	</div>
	<?php
	}
}

//Pagina backend - export to csv
function tccl_contactlist_pageexport() {
	//Redireccionamos al CSV una sola vez...
	if( ! isset( $_GET['tccl_exportcsv'] ) )
		echo '<meta http-equiv="refresh" content="1; url=options-general.php?page=tcattd-contact-list-export&tccl_exportcsv">'; //so sorry

	//Vamos a mostrar el aviso de descarga del CSV...
	?>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php _e( "Contact List", "tccl" ); ?> <a href="options-general.php?page=tcattd-contact-list" class="add-new-h2"><?php _e("View Contacts", "tccl" ); ?></a> <a href="options-general.php?page=tcattd-contact-list-add" class="add-new-h2"><?php _e("Add New Contact", "tccl" ); ?></a> <a href="options-general.php?page=tcattd-contact-list-export" onclick="return confirm('<?php _e( "Do you want to export your contact as CSV?", "tccl" ); ?>');" class="add-new-h2"><?php _e("Export to CSV", "tccl" ); ?></a></h2>

		<div class="update"><p><?php _e( "Preparing CSV for download...", "tccl" );?></p></div>
	</div>
	<?php
}

//Aqui enviamos el CSV realmente
if( $pagenow == 'options-general.php' AND isset( $_GET['tccl_exportcsv'] ) ) {
	//Acceso a la DB
	global $wpdb;

	//La consulta a exportar
	$tccl_sql = "SELECT * FROM $wpdb->tccl";

	//Exportamos
	query_to_csv($tccl_sql, "tcattd_contactlist.csv", true);

	//Fuera de aqui...
	exit();
}

//Soporte Shortcode
function shortcode_contactlist( $atts ) {
	//Necesitamos acceso a la DB
	global $wpdb;

	//En que pagina estamos?
	if ( empty( $_REQUEST['paged'] ) ) {
		$paged = 0; //Default, la primera
	} else {
		$paged = mysql_real_escape_string( (int) $_REQUEST['paged'] ) - 1;
	}

	//Items por paged
	$perpage = 10;

	//Total de items
	$totalitems = $wpdb->query("
		SELECT * FROM $wpdb->tccl
		");

	//Total de paginas
	$totalpages = ceil($totalitems/$perpage);

	//Offset
	$offset = $perpage * $paged;

	//Consulta, "$perpage" por paged
	$contactos = $wpdb->get_results(
		"
		SELECT * FROM $wpdb->tccl
		LIMIT $perpage
		OFFSET $offset
		");

	//Salida
	$output = '';
	$output .= '<table id="tcattd_contactlist">';
	$output .= '	<tr id="tccl_header">';
	$output .= '		<td><strong>' . __( "Name", "tccl" ) . '</strong></td>';
	$output .= '		<td><strong>' . __( "Last Name", "tccl" ) . '</strong></td>';
	$output .= '		<td><strong>' . __( "Email", "tccl" ) . '</strong></td>';
	$output .= '		<td><strong>' . __( "Birthday", "tccl" ) . '</strong></td>';
	$output .= '	</tr>';

	//Vamos por c/u de los registros
	foreach($contactos as $c) {
		//El cumpleaños
		$birthday = $c->bday_year . '-' . $c->bday_month . '-' . $c->bday_day;

		$output .= '<tr class="tccl_row">';
		$output .= '	<td>' . $c->name . '</td>';
		$output .= '	<td>' . $c->last_name . '</td>';
		$output .= '	<td>' . $c->email . '</td>';
		$output .= '	<td>' . $birthday . '</td>';
		$output .= '</tr>';
	}

	$output .= '</table>';

	//Paginacion
	$output .= paginate_links( array(
		'base' => str_replace( $totalitems, '%#%', get_pagenum_link( $totalitems ) ),
		'format' => '?paged=%#%',
		'current' => max( 1, get_query_var('paged') ),
		'total' => $totalpages
	) );

	return $output;
}
add_shortcode( 'contact-list', 'shortcode_contactlist' );

//Vamos a usar la class WP_List_Table de WordPress. Debemos cargarla primero
if( ! class_exists( 'WP_List_Table' ) )
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );

//Nuestra class para la tabla de contactos en el wp-admin
class tcattd_ContactTable extends WP_List_Table {
	//Lo basico de la class
	function __construct() {
		parent::__construct( array(
		'singular'=> 'wp_list_text_link',
		'plural' => 'wp_list_text_links',
		'ajax'	=> false
		) );
	}

	//Definimos las columnas de nuestra tabla de contactos
	function get_columns() {
		$columns = array(
			'name'		=>	__('Name'),
			'last_name'	=>	__('Last Name'),
			'email'		=>	__('Email'),
			'bday_year'	=>	__('Birth Year'),
			'bday_month'=>	__('Birth Month'),
			'bday_day'	=>	__('Birth Day'),
			'id'		=>	__('Manage'),
		);
		return $columns;
	}

	//Sort-eable!
	public function get_sortable_columns() {
		return $sortable = array(
			'name'		=>	array( 'name', true ),
			'last_name'	=>	array( 'last_name', true ),
		);
	}

	//Preparamos la tabla
	function prepare_items() {
		global $wpdb, $_wp_column_headers;
		$screen = get_current_screen();

		//La consulta
		$query = "SELECT * FROM $wpdb->tccl";

		//Ordeamos parametros que seran usados para ordenar los resultados
		$orderby = !empty($_GET["orderby"]) ? mysql_real_escape_string($_GET["orderby"]) : 'ASC';
		$order = !empty($_GET["order"]) ? mysql_real_escape_string($_GET["order"]) : '';
		if(!empty($orderby) & !empty($order)){ $query.=' ORDER BY '.$orderby.' '.$order; }

		//Paginacion
		//Numero de elementos en la tabla
		$totalitems = $wpdb->query($query);
		//Cuantas por pagina?
		$perpage = 10;
		//En que pagina estamos?
		$paged = !empty($_GET["paged"]) ? mysql_real_escape_string($_GET["paged"]) : '';
		//Numero de pagina
		if(empty($paged) || !is_numeric($paged) || $paged<=0 ){ $paged=1; }
		//Cuantas paginas tenemos en total?
		$totalpages = ceil($totalitems/$perpage);
		//Adjutamos la consulta para tomar en cuenta la paginacion
		if(!empty($paged) && !empty($perpage)){
	        $offset=($paged-1)*$perpage;
			$query.=' LIMIT '.(int)$offset.','.(int)$perpage;
		}

		//Registramos la paginacion. Los links de paginacion se haran automaticamente con esto
		$this->set_pagination_args( array(
	        "total_items" => $totalitems,
	        "total_pages" => $totalpages,
	        "per_page" => $perpage,
		) );

		//Registramos las columnas
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);

		//Buscamos los items en la DB
		$this->items = $wpdb->get_results($query);
	}

	//Mostramos las columnas
	function display_rows() {
		//Tomamos los registros de la funcion prepare_items
		$records = $this->items;

		//Tomamos las columnas registradas en get_columns y get_sortable_columns
		list( $columns, $hidden ) = $this->get_column_info();

		//Loop-eamos entre cada registro
		if( ! empty( $records ) ) {
			foreach( $records as $rec ){
				//Abrimos...
		        echo '<tr id="record_'.$rec->id.'">';

				foreach ( $columns as $column_name => $column_display_name ) {
					//Estilo de cada columna
					$class = "class='$column_name column-$column_name'";
					$style = "";
					if ( in_array( $column_name, $hidden ) ) $style = ' style="display:none;"';
					$attributes = $class . $style;

					//Link editar
					$editlink  = 'options-general.php?page=tcattd-contact-list-manage&action=edit&id=' . (int) $rec->id;

					//Link borrar
					$deletelink  = 'options-general.php?page=tcattd-contact-list-manage&action=delete&id=' . (int) $rec->id;

					//Mostramos la celda
					switch ( $column_name ) {
						case "id":
							echo '<td '.$attributes.'><strong><a href="'.$editlink.'" title="Edit">' . __( "Edit", "tccl" ) . '</a> or <a href="'.$deletelink.'" title="Delete" onclick="return confirm(\'' . __( "Do you really want to delete this record?", "tccl" ) . '\');">' . __( "Delete", "tccl" ) . '</a></strong></td>';
							break;
						case "name":
							echo '<td '.$attributes.'>'.stripslashes($rec->name).'</td>';
							break;
						case "last_name":
							echo '<td '.$attributes.'>'.stripslashes($rec->last_name).'</td>';
							break;
						case "email":
							echo '<td '.$attributes.'>'.$rec->email.'</td>';
							break;
						case "bday_year":
							echo '<td '.$attributes.'>'.$rec->bday_year.'</td>';
							break;
						case "bday_month":
							echo '<td '.$attributes.'>'.$rec->bday_month.'</td>';
							break;
						case "bday_day":
							echo '<td '.$attributes.'>'.$rec->bday_day.'</td>';
							break;
					}
				}

				//Cerramos
				echo '</tr>';

			}
		}
	}

} //tcattd_ContactTable

/*
http://php.net/manual/en/function.fputcsv.php
jamie@agentdesign.co.uk

With some modifications to work on top of WordPress
*/
function query_to_csv($query, $filename, $attachment = false, $headers = true) {
	global $wpdb;

    if($attachment) {
        //send response headers to the browser
       	header( 'Content-type: application/x-msdownload' );
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment;filename='.$filename);
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
        $fp = fopen('php://output', 'w');
    } else {
        $fp = fopen($filename, 'w');
    }

    $result = $wpdb->get_results($query, ARRAY_A);

    if($headers) {
        //output header row (if at least one row exists)
        if($result) {
        	$headerrow = array('ID', 'Name', 'Last Name', 'Email', 'Birth Year', 'Birth Month', 'Birth Day');
			fputcsv($fp, $headerrow);
        }
    }

    foreach ($result as $row) {
        fputcsv($fp, $row);
    }

    fclose($fp);
}
