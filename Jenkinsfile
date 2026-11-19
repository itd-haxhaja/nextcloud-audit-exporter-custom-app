@Library('Kubernetes-Lib') _

pipeline {
    options {
        buildDiscarder(logRotator(numToKeepStr: '10'))
        disableConcurrentBuilds(abortPrevious: true)
        timeout(time: 15, unit: 'MINUTES')
    }

    environment {
        TERM = 'xterm-256color'
        SERVER = 'harbor.itdesign.cloud'
        IMAGE_REGISTRY = 'harbor.itdesign.cloud/audit'
        HARBOR_USER = credentials('harbor_robot')
        IMAGE_NAME = 'nextcloud-export-app'
        BRANCH_NAME = GIT_BRANCH.replaceFirst(/origin\//, '')
    }

    agent {
        kubernetes {
            cloud 'Build-Kubernetes'
            customWorkspace '/home/jenkins/workspace'
            yaml BuildPod(name: "buildPod", binding: ['env': env])
            defaultContainer 'build'
            showRawYaml false
        }
    }

    stages {
        stage('Build Image') {
            steps {
                container('build') {
                    ansiColor('xterm') {
                        sh '''
                            cd /home/jenkins/workspace/
                            # App version from appinfo/info.xml (e.g. 1.1.0): extra image tag + OCI label
                            APP_VERSION=$(awk -F'[<>]' '/<version>/{print $3; exit}' appinfo/info.xml)
                            test -n "${APP_VERSION}" || { echo "could not read <version> from appinfo/info.xml"; exit 1; }
                            echo "Building ${IMAGE_NAME} app version ${APP_VERSION} (build ${BUILD_NUMBER})"
                            podman build \
                                --tls-verify=false \
                                --build-arg APP_VERSION=${APP_VERSION} \
                                --tag ${IMAGE_NAME}:${BUILD_NUMBER} \
                                --tag ${IMAGE_NAME}:${APP_VERSION} \
                                --tag ${IMAGE_NAME}:latest \
                                --pull --rm \
                                .
                        '''
                    }
                }
            }
        }

        stage('Smoke Test Image') {
            steps {
                container('build') {
                    ansiColor('xterm') {
                        sh '''
                            # Run the entrypoint against a scratch target dir inside the image and check
                            # that a clean app folder (no tests/vendor) comes out.
                            podman run --rm --entrypoint sh ${IMAGE_NAME}:${BUILD_NUMBER} -c '
                                set -e
                                mkdir -p /tmp/custom_apps
                                TARGET_DIR=/tmp/custom_apps install-app
                                test -f /tmp/custom_apps/audit_export/appinfo/info.xml
                                grep -q "<id>audit_export</id>" /tmp/custom_apps/audit_export/appinfo/info.xml
                                test ! -e /tmp/custom_apps/audit_export/tests
                                test ! -e /tmp/custom_apps/audit_export/vendor
                                test ! -e /tmp/custom_apps/audit_export/scripts
                            '
                        '''
                    }
                }
            }
        }

        stage('Push Image to Harbor') {
            steps {
                container('build') {
                    ansiColor('xterm') {
                        sh '''
                            cd /home/jenkins/workspace/
                            APP_VERSION=$(awk -F'[<>]' '/<version>/{print $3; exit}' appinfo/info.xml)
                            echo "Tagging and pushing to Harbor..."
                            podman tag ${IMAGE_NAME}:${BUILD_NUMBER} ${IMAGE_REGISTRY}/${IMAGE_NAME}:${BUILD_NUMBER}
                            podman tag ${IMAGE_NAME}:${BUILD_NUMBER} ${IMAGE_REGISTRY}/${IMAGE_NAME}:${APP_VERSION}
                            podman tag ${IMAGE_NAME}:${BUILD_NUMBER} ${IMAGE_REGISTRY}/${IMAGE_NAME}:latest

                            for TAG in ${BUILD_NUMBER} ${APP_VERSION} latest; do
                                podman push \
                                    --creds=${HARBOR_USER} \
                                    --tls-verify=false \
                                    ${IMAGE_REGISTRY}/${IMAGE_NAME}:${TAG} docker://${IMAGE_REGISTRY}/${IMAGE_NAME}:${TAG}
                            done
                        '''
                    }
                }
            }
        }
    }

    post {
        always {
            container('build') {
                sh '''
                    cd /home/jenkins/workspace/ || true
                    APP_VERSION=$(awk -F'[<>]' '/<version>/{print $3; exit}' appinfo/info.xml 2>/dev/null || true)
                    for TAG in ${BUILD_NUMBER} ${APP_VERSION} latest; do
                        podman rmi ${IMAGE_NAME}:${TAG} || true
                        podman rmi ${IMAGE_REGISTRY}/${IMAGE_NAME}:${TAG} || true
                    done
                '''
            }
            cleanWs()
        }
        success {
            echo "Image pushed: ${IMAGE_REGISTRY}/${IMAGE_NAME}:${BUILD_NUMBER} (also :latest and the app version tag)"
        }
        failure {
            echo 'Build failed. Check the logs for details.'
        }
    }
}
