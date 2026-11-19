allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
subprojects {
    project.evaluationDependsOn(":app")
    // The release lint check crashes inside webview_flutter_android's generated code (a lint bug,
    // "Unexpected failure during lint analysis"); it is only a pre-release check, not part of the app.
    tasks.matching { it.name.startsWith("lintVital") }.configureEach { enabled = false }
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
