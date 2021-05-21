<?php
    session_start();

    include_once '../redirects/db-management.php';
    $connection=mysqli_connect($host, $db_user, $db_password, $db_name);
    if(!$connection) die('Nie można połączyć się z bazą!');

    $login = $_SESSION['login'];
    $currentRole = mysqli_query($connection, "SELECT role FROM users WHERE login='$login'");

    if ($currentRole->num_rows > 0) {
        while($row = $currentRole->fetch_assoc()) {
          $myRole = $row["role"];
        }
      }

    if(!isset($_SESSION['logged']) || $myRole != "admin")
    {
        header('Location: ../');
        exit();
    }

    $days = array("Poniedziałek", "Wtorek", "Środa", "Czwartek", "Piątek", "Sobota", "Niedziela");

    $carriers = array("Rokbus (Rokietnica)", "ZKP Suchy Las", "Transkom (Murowana Goślina, Czerwonak)", "PUK Komorniki",
                      "PUK Dopiewo", "Warbus (Oborniki)", "Marco Polo", "PKS Poznań");

    $shifts = array("monday1", "monday2",
                    "tuesday1", "tuesday2",
                    "wednesday1", "wednesday2",
                    "thursday1", "thursday2",
                    "friday1", "friday2",
                    "saturday1", "saturday2",
                    "sunday1", "sunday2");
    $r = 0;

    if(isset($_POST['dateStart']) && isset($_POST['dateEnd']))
    {
        $everything_OK=true;

        $dateStart = $_POST['dateStart'];
        $dateEnd = $_POST['dateEnd'];
        $z = 0;
        for($x = 0; $x < sizeof($carriers); $x++)
        {
            $carrier[$x] = $_POST['carrier'.$x];
            for($y = 0; $y < sizeof($shifts); $y++)
            {
                $teams[$z] = $_POST[$shifts[$y]."a".$x]." - ".$_POST[$shifts[$y]."b".$x];
                $z++;
                //Zapamiętaj wprowadzone dane
                $_SESSION['fr_'.$shifts[$y]."a".$x] = $_POST[$shifts[$y]."a".$x];
                $_SESSION['fr_'.$shifts[$y]."b".$x] = $_POST[$shifts[$y]."b".$x];
            }
        }
        $_SESSION['fr_dateStart'] = $dateStart;
        $_SESSION['fr_dateEnd'] = $dateEnd;

        require_once "../redirects/db-schedules.php";
        mysqli_report(MYSQLI_REPORT_STRICT);

        try
        {
            $connection = new mysqli($host, $db_user, $db_password, $db_name);
            if($connection->connect_errno!=0) throw new Exception(mysqli_connect_errno());
            else
            {
                //sprawdzenie poprawnosci dat
                $datetime = new DateTime($dateStart);
                $timestampStart = $datetime->format('U');
                $fullDate = date("Y-m-d:l", $timestampStart);
                $day = substr($fullDate, 11);
                if($day != "Monday")
                {
                    $everything_OK=false;
                    $_SESSION['e_create']="Grafik musi zaczynać się od poniedziałku!";
                }
                $datetime = new DateTime($dateEnd);
                $timestampEnd = $datetime->format('U');
                $fullDate = date("Y-m-d:l", $timestampEnd);
                $day = substr($fullDate, 11);
                if($day != "Sunday")
                {
                    $everything_OK=false;
                    $_SESSION['e_create']="Grafik musi kończyć się na niedzieli!";
                }
                if($timestampEnd - $timestampStart != 518400)
                {
                    $everything_OK=false;
                    $_SESSION['e_create']="Grafik musi mieścić się w zakresie jednego tygodnia!";
                }
                //sprawdzanie istnienia grafiku
                $tmpTableName = $dateStart."_".$dateEnd;
                $tableName = str_replace("-","",$tmpTableName);

                $result = $connection->query("SELECT Table_name from information_schema.tables WHERE Table_name = '$tableName'");
                if(!$result) throw new Exception($connection->error);

                $how_many_tables = $result->num_rows;
                if($how_many_tables>0)
                {
                    $everything_OK=false;
                    $_SESSION['e_create']="Grafik z wybranego przedziału już istnieje!";
                }
                // sprawdzanie poprawnosci zespolu
                for($x = 0; $x < sizeof($carriers); $x++)
                {
                    for($y = 0; $y < sizeof($shifts); $y++)
                    {
                        if(($_POST[$shifts[$y]."a".$x] != "" && $_POST[$shifts[$y]."b".$x] != "") && ($_POST[$shifts[$y]."a".$x] == $_POST[$shifts[$y]."b".$x]))
                        {
                            $everything_OK=false;
                            $_SESSION['e_team']="Nie można wybrać dwukrotnie tego samego kontrolera!";        
                        }
                        if(($_POST[$shifts[$y]."a".$x] != "" && $_POST[$shifts[$y]."b".$x] != "") && ($_POST[$shifts[$y]."a".$x] == "ZAKAZ" && $_POST[$shifts[$y]."b".$x] != ""))
                        {
                            $everything_OK=false;
                            $_SESSION['e_team']="Nie można wybrać kontrolera tam gdzie obowiązuje zakaz!";        
                        }
                    }
                }     

                if($everything_OK==true)
                {
                    //Utwórz tabelę i dodaj kolumny
                    for($x = 0; $x < sizeof($shifts); $x++) $columns .= $shifts[$x]." VARCHAR(20),";

                    $query = "CREATE TABLE $tableName (
                        id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        carrier TEXT,"
                        .$columns.
                        "PRIMARY KEY (id)
                        )";

                    if(mysqli_query($connection, $query)) $_SESSION['sent']=true;
                    else throw new Exception($connection->error);

                    // Utwórz tabelę dyspozycyjności na przyszły tydzień
                    require_once "../redirects/db-availability.php";
                    mysqli_report(MYSQLI_REPORT_STRICT);            
                    try {
                        $availabilityCon = new mysqli($host, $db_user, $db_password, $db_name);
                        if($availabilityCon->connect_errno!=0) throw new Exception(mysqli_connect_errno());
                        else {
                            for($x = 0; $x < sizeof($shifts); $x++) $availabilityColumns .= $shifts[$x]." VARCHAR(3),";

                            $nextTsStart = $timestampStart + 604800;
                            $nextTsEnd = $timestampEnd + 604800;
                            $nextDateStart = date('Ymd', $nextTsStart);
                            $nextDateEnd = date('Ymd', $nextTsEnd);
                            $availabilityTableName = $nextDateStart.'_'.$nextDateEnd;

                            $availabilityQuery = "CREATE TABLE $availabilityTableName (
                                tkid SMALLINT,"
                                .$availabilityColumns.
                                "date TEXT,
                                PRIMARY KEY (tkid)
                                )";

                            if(mysqli_query($availabilityCon, $availabilityQuery)) $_SESSION['sent']=true;
                            else throw new Exception($availabilityCon->error);
                        }
                    }
                    catch (Exception $e) {
                        echo '<span style="color:red;">Błąd serwera! Przepraszamy za niedogodności i prosimy o rejestrację w innym terminie!</span>';
                        echo '<br>Informacja developerska: '.$e;            
                    }

                    // Dodaj wiersze
                    $z = 0;
                    for($y=0; $y < sizeof($carriers); $y++)
                    {
                        $values .= "('".$carriers[$y]."', ";
                        for($x=0; $x < sizeof($shifts); $x++)
                        {
                            if($x + 1 == sizeof($shifts)) $values .= "'".$teams[$z]."')";
                            else $values .= "'".$teams[$z]."', ";
                            $z++;
                        }
                        if($y + 1 != sizeof($carriers)) $values .= ",";
                    }

                    $query = "INSERT INTO $tableName (carrier, ".implode(", ", $shifts).") VALUES".$values;

                    if(mysqli_query($connection, $query)) 
                    {
                        $_SESSION['sent'] = true;
                        header('Location: ../grafik');
                        if(isset($_SESSION['e_create'])) unset($_SESSION['e_create']);
                    }
                    else throw new Exception($connection -> error);
                }        
            }
        }
        catch(Exception $e)
        {
            echo '<span style="color:red;">Błąd serwera! Przepraszamy za niedogodności i prosimy o rejestrację w innym terminie!</span>';
            echo '<br>Informacja developerska: '.$e;
        }
    }
    include '../redirects/db-management.php';
    $connection=mysqli_connect($host, $db_user, $db_password, $db_name);
    if(!$connection) die('Nie można połączyć się z bazą!');
    $tkid = mysqli_query($connection, "SELECT tkid FROM users WHERE role = 'user'");
?>
<!DOCTYPE HTML>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <link rel="stylesheet" href="../../styles/panel.css">
</head>
<body>
    <a href="../grafik"><h2>POWRÓT</h2></a>
    <form method="post" enctype="multipart/form-data">
        <input type="date" value="<?php
        if(isset($_SESSION['fr_dateStart']))
        {
            echo $_SESSION['fr_dateStart'];
            unset($_SESSION['fr_dateStart']);
        }
        ?>" name="dateStart" required>
        <input type="date" value="<?php
        if(isset($_SESSION['fr_dateEnd']))
        {
            echo $_SESSION['fr_dateEnd'];
            unset($_SESSION['fr_dateEnd']);
        }
        ?>" name="dateEnd" required>
        <table border = "1px, solid, black">
            <tr>
                <td rowspan = "2">Przewoźnik</td>
                <?php for($x = 0; $x < sizeof($days); $x++) {?>
                    <td colspan = "2"><?php echo $days[$x]; ?></td>
                <?php } ?>
            </tr>
            <tr>
                <?php for($x = 0; $x < 7; $x++) {?>
                    <td>06:00 - 14:00</td>
                    <td>14:00 - 22:00</td>
                    <?php } ?>
            </tr>
            <?php for($x = 0; $x < sizeof($carriers); $x++) { $i = 0; ?>
            <input type="hidden" name=<?php echo "carrier".$x;?> value="<?php echo $carriers[$x];?>">
            <tr>
                <td><?php echo $carriers[$x];?></td>
                <?php for($y = 0; $y < sizeof($shifts); $y++) {?>
                <td>
                    <select name = <?php echo $shifts[$i]."a".$r; ?>>
                        <option></option>
                        <?php 
                            $tkid = mysqli_query($connection, "SELECT tkid FROM users WHERE role = 'user'");
                            if ($tkid->num_rows > 0) while($row = $tkid->fetch_assoc()) {?>
                            <option <?php if ($_SESSION['fr_'.$shifts[$i]."a".$r] == $row["tkid"]) echo 'selected="selected" ';?>><?php echo $row["tkid"]; ?></option>
                            <?php } ?>
                        <option <?php if ($_SESSION['fr_'.$shifts[$i]."a".$r] == "ZAKAZ") echo 'selected="selected" ';?>>ZAKAZ</option>
                    </select>
                    <select name = <?php echo $shifts[$i]."b".$r; ?>>
                        <option></option>
                        <?php 
                            $tkid = mysqli_query($connection, "SELECT tkid FROM users WHERE role = 'user'");
                            if ($tkid->num_rows > 0) while($row = $tkid->fetch_assoc()) {?>
                            <option <?php if ($_SESSION['fr_'.$shifts[$i]."b".$r] == $row["tkid"]) echo 'selected="selected" ';?>><?php echo $row["tkid"]; ?></option>
                            <?php } ?>
                    </select>
                </td><?php $i++; } ?>
            </tr><?php $r++; } ?>
        </table>
        <input type="submit" value="DODAJ">
    </form>
    <?php
        if(isset($_SESSION['e_create']))
        {
            // if(isset($_SESSION['e_team'])) unset($_SESSION['e_team']);
            echo '<div class="error">'.$_SESSION['e_create'].'</div>';
            unset($_SESSION['e_create']);
        }
        if(isset($_SESSION['e_team']))
        {
            // if(isset($_SESSION['e_create'])) unset($_SESSION['e_create']);
            echo '<div class="error">'.$_SESSION['e_team'].'</div>';
            unset($_SESSION['e_team']);
        }
        $connection->close();
    ?>
</body>
